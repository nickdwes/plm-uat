<?php

namespace App\Console\Commands;

use App\Models\Task\TaskPrerequisite;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Remind the people on a task to arrange and authorize travel, five days before
 * the task starts.
 *
 * Travel prerequisites carry a `due_on` set to five days before the task's
 * start date. This fires once per prerequisite when that date arrives and the
 * travel is still open, so advance planning happens instead of a scramble the
 * day before.
 */
class RemindTaskTravel extends Command
{
    protected $signature = 'tasks:remind-travel';

    protected $description = 'Remind task owners to plan and authorize travel 5 days before the task starts';

    public function handle(NotificationService $notifications): int
    {
        $today = now()->toDateString();

        $due = TaskPrerequisite::with(['task.members.employee', 'task.assignee'])
            ->where('type', 'travel')
            ->where('status', '!=', TaskPrerequisite::STATUS_DONE)
            ->whereNull('reminded_at')
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<=', $today)
            ->get();

        $sent = 0;

        foreach ($due as $prereq) {
            $task = $prereq->task;
            if (! $task) {
                continue;
            }

            foreach ($this->recipients($task) as $userId) {
                $notifications->create(
                    (string) $prereq->organization_id,
                    $userId,
                    'task.travel_reminder',
                    'Travel to arrange',
                    "“{$task->title}” starts on " . ($task->started_on?->toFormattedDateString() ?? 'soon')
                        . '. Plan and authorize travel now.',
                    ['task_id' => $task->id, 'project_id' => $task->project_id],
                    \App\Models\Task\Task::class,
                    (string) $task->id,
                );
                $sent++;
            }

            $prereq->update(['reminded_at' => now()]);
        }

        $this->info("Travel reminders: {$due->count()} due, {$sent} notifications sent.");

        return self::SUCCESS;
    }

    /** The users to remind: the task's assignees, else whoever it is assigned to. */
    private function recipients(\App\Models\Task\Task $task): array
    {
        $ids = $task->members
            ->where('capacity', 'assignee')
            ->map(fn ($m) => $m->employee?->user_id)
            ->filter()
            ->values()
            ->all();

        if (! $ids && $task->assignee?->user_id) {
            $ids = [$task->assignee->user_id];
        }

        return array_values(array_unique($ids));
    }
}
