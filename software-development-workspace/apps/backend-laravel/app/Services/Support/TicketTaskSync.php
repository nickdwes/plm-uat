<?php

namespace App\Services\Support;

use App\Models\Support\Ticket;
use App\Models\Task\Task;
use App\Services\Task\TaskGenerator;

/**
 * Every ticket carries a task — the trackable unit of work behind it — routed
 * to the same team or individual the ticket is.
 *
 * Idempotent through TaskGenerator: one task per ticket, kept in step with the
 * ticket's routing and resolution. The ticket is the source of truth for its
 * support task, so a later sync re-applies the ticket's assignee, team and
 * terminal state rather than letting the two drift.
 */
class TicketTaskSync
{
    public function __construct(private readonly TaskGenerator $generator)
    {
    }

    public function sync(Ticket $ticket): Task
    {
        $ticket->loadMissing(['assignee', 'status', 'priority']);

        $assigneeEmployeeId = $ticket->assignee?->employeeId();
        $priority           = $this->mapPriority($ticket->priority?->name);
        $isTerminal         = in_array($ticket->status?->state, ['resolved', 'closed'], true);

        // One task per ticket, created once.
        $task = $this->generator->once(
            $ticket->organization_id,
            Ticket::class,
            (string) $ticket->id,
            'support',
            [
                'category'        => 'support',
                'title'           => trim("[{$ticket->reference}] {$ticket->subject}"),
                'priority'        => $priority,
                'assigned_to'     => $assigneeEmployeeId,
                'support_team_id' => $ticket->support_team_id,
                'handover_policy' => 'transferable',
                'status'          => $isTerminal ? Task::DONE : 'todo',
                'completed_at'    => $isTerminal ? now() : null,
            ],
        );

        // Keep routing and resolution in step on later calls.
        $updates = [
            'assigned_to'     => $assigneeEmployeeId,
            'support_team_id' => $ticket->support_team_id,
            'priority'        => $priority,
        ];

        if ($isTerminal && $task->status !== Task::DONE) {
            $updates['status'] = Task::DONE;
            $updates['completed_at'] = now();
        } elseif (! $isTerminal && $task->status === Task::DONE) {
            // The ticket reopened — the work is not done after all.
            $updates['status'] = 'in_progress';
            $updates['completed_at'] = null;
        }

        $task->update($updates);

        return $task;
    }

    /** Ticket priority names vary; map by keyword to the task's three levels. */
    private function mapPriority(?string $name): string
    {
        $n = strtolower((string) $name);

        return match (true) {
            str_contains($n, 'high') || str_contains($n, 'urgent')
                || str_contains($n, 'critical') || str_contains($n, 'p1') || str_contains($n, 'p2') => 'high',
            str_contains($n, 'low') || str_contains($n, 'minor') || str_contains($n, 'p4') => 'low',
            default => 'medium',
        };
    }
}
