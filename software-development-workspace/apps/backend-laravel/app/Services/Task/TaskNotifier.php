<?php

namespace App\Services\Task;

use App\Models\Employee;
use App\Models\Task\Task;
use App\Models\Task\TaskIssue;
use App\Models\Task\TaskRequest;
use App\Services\NotificationService;

/**
 * Telling people about their tasks.
 *
 * The whole point of the request model is that somebody was asked. The legacy
 * left 261 of its 354 delegations unanswered, and an inbox alone only fixes
 * that for people who think to open it — which is most of the way back to the
 * original problem. It sent 14,427 notifications for a reason.
 *
 * Four moments, and no more. Everything here is a thing that stops somebody
 * else's work until they act:
 *
 *   You were asked to assist, take over, or collaborate.
 *   Your request was answered.
 *   An issue was escalated to you.
 *   A task is waiting on your approval, or yours came back.
 *
 * Deliberately not notified: task created, task edited, comment added. Those
 * are the notifications people learn to ignore, and once they do, the four
 * above stop working too.
 */
class TaskNotifier
{
    public function __construct(private NotificationService $notifications)
    {
    }

    /** Somebody has been asked to help with a task. */
    public function requested(Task $task, TaskRequest $request): void
    {
        $verb = match ($request->kind) {
            'delegate'    => 'asked you to take over',
            'collaborate' => 'asked you to collaborate on',
            default       => 'asked for your help with',
        };

        $this->send(
            $request->to_employee_id,
            $task,
            'task.requested',
            $this->nameOf($request->requested_by) . ' ' . $verb . ' a task',
            $task->title,
            ['kind' => $request->kind, 'request_id' => $request->id],
        );
    }

    /**
     * A request was answered.
     *
     * Sent to whoever asked, because silence and a decline look identical from
     * their side until somebody says so.
     */
    public function answered(Task $task, TaskRequest $request): void
    {
        $this->send(
            $request->requested_by,
            $task,
            'task.request_answered',
            $this->nameOf($request->to_employee_id) . ' ' . $request->status . ' your ' . $request->kind . ' request',
            $task->title,
            ['status' => $request->status, 'request_id' => $request->id],
        );
    }

    /** An issue has been put in front of somebody by name. */
    public function escalated(Task $task, TaskIssue $issue, int $toEmployeeId, ?int $byEmployeeId): void
    {
        $this->send(
            $toEmployeeId,
            $task,
            'task.issue_escalated',
            $this->nameOf($byEmployeeId) . ' escalated an issue to you',
            $issue->name,
            ['issue_id' => $issue->id, 'priority' => $issue->priority],
        );
    }

    /**
     * A late task has climbed to somebody.
     *
     * The reason is in the message rather than the title, because the title is
     * what a notification list shows and "Task overdue" beside a task name is
     * the whole point — the days and the dates are for whoever opens it.
     */
    public function escalatedTask(Task $task, \App\Models\Task\TaskEscalation $escalation): void
    {
        $this->send(
            $escalation->to_employee_id,
            $task,
            'task.escalated',
            \App\Models\Task\TaskEscalation::TRIGGERS[$escalation->trigger] . ': ' . $task->title,
            $escalation->detail,
            [
                'trigger' => $escalation->trigger,
                'level'   => $escalation->level,
                'task_reference' => $task->reference,
                'project' => $task->project?->name,
            ],
        );
    }

    /** A task is waiting on somebody's sign-off. */
    public function awaitingApproval(Task $task): void
    {
        $this->send(
            $task->approver_id,
            $task,
            'task.awaiting_approval',
            'A task is waiting on your approval',
            $task->title,
        );
    }

    /**
     * Approved or sent back.
     *
     * Goes to whoever owns the task, because they are the one who has to act
     * on a rejection.
     */
    public function approvalDecided(Task $task, bool $approved, ?string $note): void
    {
        $this->send(
            $task->assigned_to,
            $task,
            $approved ? 'task.approved' : 'task.rejected',
            $approved ? 'Your task was approved' : 'Your task was sent back',
            $note ?: $task->title,
        );
    }

    // ── Plumbing ──────────────────────────────────────────────────────────

    /**
     * Notifications go to users; tasks name employees.
     *
     * An employee with no login cannot be notified. Silently doing nothing is
     * right here — the alternative is a write that fails and takes the action
     * that triggered it down with it, so somebody could not be asked to help
     * merely because they have no account yet.
     */
    private function send(
        ?int $employeeId,
        Task $task,
        string $type,
        string $title,
        ?string $message = null,
        ?array $data = null,
    ): void {
        if ($employeeId === null) {
            return;
        }

        $userId = Employee::whereKey($employeeId)->value('user_id');

        if ($userId === null) {
            return;
        }

        $this->notifications->create(
            (string) $task->organization_id,
            $userId,
            $type,
            $title,
            $message,
            ($data ?? []) + ['task_id' => $task->id, 'reference' => $task->reference],
            Task::class,
            (string) $task->id,
        );
    }

    private function nameOf(?int $employeeId): string
    {
        if ($employeeId === null) {
            return 'Somebody';
        }

        return Employee::whereKey($employeeId)->first()?->full_name ?? 'Somebody';
    }
}
