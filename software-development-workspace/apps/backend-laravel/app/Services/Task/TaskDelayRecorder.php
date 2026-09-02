<?php

namespace App\Services\Task;

use App\Models\Task\Task;
use App\Models\Task\TaskDeadlineExtension;
use App\Models\Task\TaskIssue;
use App\Models\Task\TaskPause;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The two ways a task slips, written down.
 *
 * Moving a deadline and stopping the clock are different actions but the same
 * shape: something is delaying the work, somebody owns that delay, and both
 * need to surface somewhere other than the task itself or nobody outside it
 * will ever know. So both record their own row and both raise an issue.
 *
 * The issue is the part that matters for governance. A delay recorded only on
 * the task is a delay only the person who caused it can see, and the legacy's
 * 261 unanswered delegations are what that looks like once it scales.
 */
class TaskDelayRecorder
{
    /**
     * Move a deadline, on the record.
     *
     * The old date is kept because the count of moves is the signal — a task
     * that has slipped four times is not the same as one estimated correctly,
     * and with a single column they read identically afterwards.
     */
    public function extend(Task $task, array $data, User $actor): TaskDeadlineExtension
    {
        if ($task->is_done) {
            throw new RuntimeException('A finished task has no deadline left to move.');
        }

        $previous = $task->deadline;

        if ($previous && $data['new_deadline'] <= $previous->toDateString()) {
            throw new RuntimeException('An extension moves the deadline later; this one does not.');
        }

        return DB::transaction(function () use ($task, $data, $actor, $previous) {
            $extension = TaskDeadlineExtension::create([
                'organization_id'   => $task->organization_id,
                'task_id'           => $task->id,
                'previous_deadline' => $previous?->toDateString(),
                'new_deadline'      => $data['new_deadline'],
                'reason'            => $data['reason'],
                'impact'            => $data['impact'],
                'dependency'        => $data['dependency'] ?? null,
                'responsible_party' => $data['responsible_party'] ?? null,
                'requested_by'      => $actor->id,
            ]);

            $task->update([
                'deadline' => $data['new_deadline'],
                // The delay belongs to somebody, and that is who the task is
                // now waiting on.
                'responsibility' => $data['responsible_party'] ?? $task->responsibility,
            ]);

            $count = $task->extensions()->count();

            $issue = $this->raiseIssue(
                $task, $actor,
                "Deadline moved" . ($previous ? " from {$previous->toDateString()}" : '') . " to {$data['new_deadline']}",
                $this->extensionDetail($task, $data, $previous, $count),
                // A first slip is information. A fourth is a problem with the
                // plan, and should not arrive at the same volume.
                $count >= 3 ? 'high' : 'medium',
            );

            if ($issue) {
                $extension->update(['task_issue_id' => $issue->id]);
            }

            return $extension->fresh();
        });
    }

    /** Stop the clock, saying who it is stopped on and when it is due back. */
    public function pause(Task $task, array $data, User $actor): TaskPause
    {
        if ($task->is_done) {
            throw new RuntimeException('A finished task is not waiting on anybody.');
        }

        if ($task->is_paused) {
            throw new RuntimeException('This task is already paused.');
        }

        $category = TaskPause::categoryFor($data['reason']);
        if ($category === null) {
            throw new RuntimeException('That is not a reason this system knows.');
        }

        return DB::transaction(function () use ($task, $data, $actor, $category) {
            $party = $data['responsible_party'] ?? TaskPause::REASONS[$category]['party'];

            $pause = TaskPause::create([
                'organization_id'    => $task->organization_id,
                'task_id'            => $task->id,
                'category'           => $category,
                'reason'             => $data['reason'],
                'note'               => $data['note'] ?? null,
                'expected_resume_on' => $data['expected_resume_on'] ?? null,
                'responsible_party'  => $party,
                'paused_by'          => $actor->id,
                'paused_at'          => now(),
            ]);

            // Pausing is itself the answer to "who are we waiting on".
            $task->update(['responsibility' => $party]);

            $issue = $this->raiseIssue(
                $task, $actor,
                'Paused — ' . $this->words($data['reason']),
                $this->pauseDetail($task, $pause),
                'medium',
            );

            if ($issue) {
                $pause->update(['task_issue_id' => $issue->id]);
            }

            return $pause->fresh();
        });
    }

    /** Start it again, and close the issue the pause raised. */
    public function resume(Task $task, ?string $note, User $actor): TaskPause
    {
        $pause = $task->pauses()->whereNull('resumed_at')->latest('id')->first();

        if (! $pause) {
            throw new RuntimeException('This task is not paused.');
        }

        return DB::transaction(function () use ($task, $pause, $note, $actor) {
            $pause->update([
                'resumed_at'  => now(),
                'resumed_by'  => $actor->id,
                'resume_note' => $note,
            ]);

            // The wait is over, so the task is ours again — unless somebody has
            // since said otherwise.
            if ($task->responsibility === $pause->responsible_party) {
                $task->update(['responsibility' => 'self']);
            }

            $pause->issue?->update([
                'status'      => 'resolved',
                'resolved_at' => now(),
                'resolved_by' => $actor->employeeId(),
            ]);

            return $pause->fresh();
        });
    }

    // ── The issue each of them raises ────────────────────────────────────────

    /**
     * Raise the delay where somebody other than the task's owner will see it.
     *
     * Only where there is something to raise it against: the spec asks for this
     * on project and support work, which is where a slipping task has a
     * customer or a delivery date behind it. A personal reminder that moves by
     * a day does not need a governance record.
     */
    private function raiseIssue(Task $task, User $actor, string $name, string $details, string $priority): ?TaskIssue
    {
        $matters = $task->project_id !== null
            || in_array($task->category, ['project', 'support', 'ticket'], true);

        if (! $matters) {
            return null;
        }

        return $task->issues()->create([
            'organization_id' => $task->organization_id,
            'kind'      => 'issue',
            'name'      => $name,
            'details'   => $details,
            'priority'  => $priority,
            'status'    => 'open',
            'raised_by' => $actor->employeeId(),
        ]);
    }

    private function extensionDetail(Task $task, array $data, $previous, int $count): string
    {
        return implode("\n", array_filter([
            $task->reference ? "Task {$task->reference}: {$task->title}" : "Task: {$task->title}",
            $task->project ? "Project: {$task->project->name}" : null,
            'Previous deadline: ' . ($previous?->toDateString() ?? 'none set'),
            "New deadline: {$data['new_deadline']}",
            "Reason: {$data['reason']}",
            "Impact: {$data['impact']}",
            ! empty($data['dependency']) ? "Blocked by: {$data['dependency']}" : null,
            ! empty($data['responsible_party']) ? 'Responsible: ' . $this->words($data['responsible_party']) : null,
            'Owner: ' . ($task->assignee?->full_name ?? 'unassigned'),
            $count > 1 ? "This is extension {$count} on this task." : null,
        ]));
    }

    private function pauseDetail(Task $task, TaskPause $pause): string
    {
        return implode("\n", array_filter([
            $task->reference ? "Task {$task->reference}: {$task->title}" : "Task: {$task->title}",
            $task->project ? "Project: {$task->project->name}" : null,
            'Waiting on: ' . $this->words($pause->responsible_party ?? 'somebody'),
            'For: ' . $this->words($pause->reason),
            $pause->expected_resume_on ? 'Expected back: ' . $pause->expected_resume_on->toDateString() : 'No expected resume date given.',
            $pause->note,
            'Owner: ' . ($task->assignee?->full_name ?? 'unassigned'),
        ]));
    }

    private function words(string $slug): string
    {
        return ucfirst(str_replace('_', ' ', $slug));
    }
}
