<?php

namespace App\Services\Project;

use App\Models\Project\Project;
use App\Models\Project\ProjectActivity;
use App\Models\Task\Task;

/**
 * The project's timeline.
 *
 * Written by the application rather than typed by anybody. A project's activity
 * feed is only worth having if it records what actually happened without
 * somebody remembering to say so — and the events worth recording are the ones
 * people ask about afterwards: when did this task get done, who put it back,
 * when was that milestone invoiced.
 *
 * Deliberately not an audit log. Every field change would bury the four events
 * anybody reads, and the database is the record of what a row says now.
 */
class ProjectActivityService
{
    public function record(
        Project $project,
        string $type,
        string $body,
        ?Task $task = null,
        ?int $actorId = null,
        ?int $employeeId = null,
    ): ProjectActivity {
        return $project->activities()->create([
            'organization_id' => $project->organization_id,
            'task_id' => $task?->id,
            'kind'            => 'system',
            'type'            => $type,
            'body'            => $body,
            'occurred_at'     => now(),
            'employee_id'     => $employeeId,
            'created_by'      => $actorId,
        ]);
    }

    /**
     * A task changing state.
     *
     * Only the transitions worth reading: created, finished, and put back. A
     * priority edit is not a story.
     */
    public function taskChanged(Project $project, Task $task, string $was, ?int $actorId, ?int $employeeId): void
    {
        if ($was === $task->status) {
            return;
        }

        $body = match ($task->status) {
            Task::DONE => "Completed “{$task->title}”",
            default => $was === Task::DONE
                ? "Reopened “{$task->title}”"
                : null,
        };

        if ($body !== null) {
            $this->record($project, 'status', $body, $task, $actorId, $employeeId);
        }
    }
}
