<?php

namespace App\Services\Task;

use App\Models\LeaveRequest;
use App\Models\Task\Task;
use App\Models\Task\TaskHandover;
use Illuminate\Support\Collection;

/**
 * Working out what happens to somebody's work while they are away.
 *
 * The legacy listed every open task and asked for a name against each — 4,361
 * rows across 548 leave requests, whose notes are mostly the word "Test". That
 * is what a form looks like once people stop reading it, and they stopped
 * reading because most of what it asked made no sense: nobody can cover your
 * certification exam or take over your research thread for a fortnight.
 *
 * So the list is split before it is shown, and the two halves ask different
 * questions:
 *
 *   Coverable work asks "who". Support, tickets, accounts and live deals, where
 *   the cost of waiting a fortnight lands on somebody outside the company.
 *
 *   The rest asks "what do we do about it". That question only matters for the
 *   ones falling due while the person is away — and those are the ones the
 *   legacy could never surface, because it could not tell the two apart.
 */
class LeaveHandoverService
{
    /**
     * The picture, before anybody names anyone.
     *
     * Both halves are returned even when empty. A leave request with nothing to
     * hand over is a fact worth showing — the absence of a list reads as a page
     * that failed to load.
     */
    public function prepare(LeaveRequest $leave): array
    {
        $tasks = $this->openTasksOf($leave);

        [$coverable, $personal] = $tasks->partition->is_transferable;

        return [
            'coverable' => $coverable->values(),

            /*
             * Split again, because only one half needs a decision.
             *
             * A personal task due long after the person is back is simply
             * waiting for them. One falling due while they are away will be
             * late unless it is moved, finished early, or knowingly allowed to
             * slip — and that is a call somebody has to make before they go.
             */
            'at_risk'  => $personal->filter(fn (Task $t) => $this->fallsDue($t, $leave))->values(),
            'personal' => $personal->reject(fn (Task $t) => $this->fallsDue($t, $leave))->values(),
        ];
    }

    /**
     * Hand one task to somebody for the duration of the leave.
     *
     * Refuses the ones that cannot be covered rather than quietly allowing an
     * override — the classification is only worth having if it holds. Somebody
     * who disagrees changes the task's own policy, which is a deliberate act on
     * the task rather than a shortcut buried in a leave form.
     */
    public function assign(
        LeaveRequest $leave,
        Task $task,
        int $toEmployeeId,
        ?string $note = null,
        ?int $createdByUserId = null,
    ): TaskHandover {
        abort_unless($task->is_transferable, 422, $task->handover_block_reason ?? 'This task cannot be handed over.');
        abort_if($toEmployeeId === $leave->employee_id, 422, 'They are the one going away.');

        return TaskHandover::updateOrCreate(
            ['task_id' => $task->id, 'leave_request_id' => $leave->id],
            [
                'organization_id'  => $leave->organization_id,
                'from_employee_id' => $task->assigned_to ?? $leave->employee_id,
                'to_employee_id'   => $toEmployeeId,
                'note'             => $note,
                'starts_on'        => $leave->from_date,
                'ends_on'          => $leave->to_date,
                // Naming somebody new revives the cover rather than leaving a
                // released row that would read as "already handed back".
                'released_at'      => null,
                'created_by'       => $createdByUserId,
            ],
        );
    }

    /** Take one back before the leave is over. */
    public function release(TaskHandover $handover): TaskHandover
    {
        $handover->update(['released_at' => now()]);

        return $handover;
    }

    /**
     * Hand everything back.
     *
     * Called when the leave ends or is cancelled. Idempotent, because the
     * nightly sweep and somebody pressing the button both end up here and
     * neither should care which got there first.
     */
    public function releaseAllFor(LeaveRequest $leave): int
    {
        return TaskHandover::where('leave_request_id', $leave->id)
            ->standing()
            ->update(['released_at' => now()]);
    }

    /**
     * What is still open and belongs to the person going away.
     *
     * Tasks they are covering for somebody else are excluded: passing on cover
     * you were only lent is how a task ends up three people from its owner with
     * nobody sure who has it.
     */
    private function openTasksOf(LeaveRequest $leave): Collection
    {
        return Task::query()
            ->where('organization_id', $leave->organization_id)
            ->where('assigned_to', $leave->employee_id)
            ->open()
            ->with(['project', 'activeHandover.coverer'])
            ->orderBy('deadline')
            ->get();
    }

    /** Due, or already late, at any point before they are back. */
    private function fallsDue(Task $task, LeaveRequest $leave): bool
    {
        return $task->deadline !== null
            && $task->deadline->lessThanOrEqualTo($leave->to_date);
    }
}
