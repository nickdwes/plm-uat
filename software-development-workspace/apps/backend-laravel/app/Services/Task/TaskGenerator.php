<?php

namespace App\Services\Task;

use App\Models\Task\Task;
use App\Models\Task\TaskRecurrence;
use App\Support\Account\ReferenceSequence;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Raising tasks that nobody typed.
 *
 * A third of the legacy's 9,055 tasks came from somewhere other than a person
 * filling in a form, and it kept a `source` column saying so — then never used
 * it, so nobody could tell a task they had been given from one they had
 * written.
 *
 * Everything generated goes through `once()`. The reason is dull and the whole
 * point: a generator that runs on a schedule runs twice. After a deploy, after
 * a retry, after somebody reruns it by hand, after two workers pick up the same
 * tick. Anything without a key that says "this exact thing already produced
 * this exact task" makes a duplicate every single time, and a task list nobody
 * trusts is worse than no generator at all.
 *
 * The key is enforced by a unique index and the duplicate is caught, rather
 * than checked for first. Two workers can both pass a check.
 */
class TaskGenerator
{
    /**
     * Raise a task for a thing that has not raised one yet.
     *
     * Returns the existing task where it has already been raised, so callers
     * can treat generation as safe to repeat rather than something to guard.
     */
    public function once(
        int $organizationId,
        string $fromType,
        string $fromId,
        string $source,
        array $attributes,
    ): Task {
        $existing = Task::withTrashed()
            ->where('organization_id', $organizationId)
            ->where('generated_from_type', $fromType)
            ->where('generated_from_id', $fromId)
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return Task::create($attributes + [
                'organization_id'     => $organizationId,
                'reference'           => ReferenceSequence::next('TK', 'tasks', $organizationId),
                'source'              => $source,
                'generated_from_type' => $fromType,
                'generated_from_id'   => $fromId,
            ]);
        } catch (QueryException $e) {
            /*
             * Somebody else won the race between the check above and this
             * insert. The unique index did its job; fetch what they made.
             *
             * Rethrown if it was any other failure, because swallowing a real
             * error here would turn a broken generator into a silent one.
             */
            if (! $this->isDuplicate($e)) {
                throw $e;
            }

            return Task::withTrashed()
                ->where('organization_id', $organizationId)
                ->where('generated_from_type', $fromType)
                ->where('generated_from_id', $fromId)
                ->firstOrFail();
        }
    }

    /**
     * Everything a schedule owes, up to today.
     *
     * A schedule nobody ran for three weeks owes three tasks, not one. Quietly
     * raising only the latest is how a monthly compliance check becomes a
     * quarterly one without anybody deciding to change it.
     */
    public function runRecurrence(TaskRecurrence $recurrence, ?CarbonImmutable $asOf = null): array
    {
        $asOf  = $asOf ?? CarbonImmutable::now();
        $made  = [];
        $last  = null;

        foreach ($recurrence->due($asOf) as $occurrence) {
            $made[] = $this->once(
                $recurrence->organization_id,
                'task_recurrence',
                // The period is part of the key, so January's task and
                // February's are different things and neither can be doubled.
                $recurrence->id . ':' . $occurrence->toDateString(),
                'recurrence',
                [
                    'title'       => $recurrence->title,
                    'description' => $recurrence->description,
                    'category'    => $recurrence->category,
                    'priority'    => $recurrence->priority,
                    'assigned_to' => $recurrence->assigned_to,
                    'effort_estimate_days' => $recurrence->effort_estimate_days,
                    'project_id'  => $recurrence->project_id,
                    'account_id'  => $recurrence->account_id,
                    // Relative to the occurrence, not a fixed date — "within a
                    // week of the first of the month", not "the eighth forever".
                    'deadline'    => $occurrence->addDays($recurrence->lead_days)->toDateString(),
                ],
            );

            $last = $occurrence;
        }

        if ($last !== null) {
            // Moved only after the tasks exist, so a failure halfway through
            // is retried rather than skipped.
            $recurrence->update(['last_generated_for' => $last->toDateString()]);
        }

        return $made;
    }

    private function isDuplicate(QueryException $e): bool
    {
        // 23505 is Postgres' unique_violation; 23000 is the ANSI class the
        // other drivers report, kept so this does not become a Postgres-only
        // safety net if the test database ever differs.
        return in_array($e->getCode(), ['23505', '23000'], true);
    }
}
