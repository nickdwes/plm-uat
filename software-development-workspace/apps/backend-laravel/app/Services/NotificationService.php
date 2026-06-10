<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;

class NotificationService
{
    /**
     * Create a notification for a user
     */
    public function create(
        string $organizationId,
        int $userId,
        string $type,
        string $title,
        ?string $message = null,
        ?array $data = null,
        ?string $notifiableType = null,
        ?string $notifiableId = null
    ): Notification {
        return Notification::create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'notifiable_type' => $notifiableType,
            'notifiable_id' => $notifiableId,
            'sent_at' => now(),
        ]);
    }

    /**
     * Create notifications for multiple users
     */
    public function createBatch(
        string $organizationId,
        array $userIds,
        string $type,
        string $title,
        ?string $message = null,
        ?array $data = null
    ): Collection {
        $notifications = collect();

        foreach ($userIds as $userId) {
            $notification = $this->create(
                $organizationId,
                $userId,
                $type,
                $title,
                $message,
                $data
            );
            $notifications->push($notification);
        }

        return $notifications;
    }

    /**
     * Get unread notifications for a user
     */
    public function getUnread(int $userId, string $organizationId, int $perPage = 20)
    {
        return Notification::byOrganization($organizationId)
            ->where('user_id', $userId)
            ->unread()
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Get all notifications for a user
     */
    public function getAll(int $userId, string $organizationId, int $perPage = 20)
    {
        return Notification::byOrganization($organizationId)
            ->where('user_id', $userId)
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(string $notificationId): Notification
    {
        $notification = Notification::findOrFail($notificationId);
        $notification->markAsRead();
        return $notification;
    }

    /**
     * Mark all unread notifications as read
     */
    public function markAllAsRead(int $userId, string $organizationId): int
    {
        return Notification::byOrganization($organizationId)
            ->where('user_id', $userId)
            ->unread()
            ->update(['read_at' => now()]);
    }

    /**
     * Get unread count
     */
    public function getUnreadCount(int $userId, string $organizationId): int
    {
        return Notification::byOrganization($organizationId)
            ->where('user_id', $userId)
            ->unread()
            ->count();
    }

    /**
     * Delete a notification
     */
    public function delete(string $notificationId): bool
    {
        return Notification::findOrFail($notificationId)->delete();
    }

    /**
     * Send training assignment notification to manager
     */
    public function notifyTrainingAssignment(
        string $organizationId,
        User $manager,
        array $assignmentData
    ): Notification {
        return $this->create(
            $organizationId,
            $manager->id,
            'training_assigned',
            'New Training Assignment',
            "{$assignmentData['employee_name']} has been assigned to {$assignmentData['training_title']}",
            [
                'training_item_id' => $assignmentData['training_item_id'],
                'employee_id' => $assignmentData['employee_id'],
                'assignment_type' => $assignmentData['assignment_type'],
                'due_date' => $assignmentData['due_date'] ?? null,
            ],
            'TrainingAssignment',
            $assignmentData['assignment_id'] ?? null
        );
    }

    /**
     * Send training completion notification
     */
    public function notifyTrainingCompletion(
        string $organizationId,
        int $employeeId,
        array $completionData
    ): Notification {
        return $this->create(
            $organizationId,
            $employeeId,
            'training_completed',
            'Training Completed',
            "You have successfully completed {$completionData['training_title']}",
            [
                'training_item_id' => $completionData['training_item_id'],
                'record_id' => $completionData['record_id'],
            ],
            'EmployeeTrainingRecord',
            $completionData['record_id'] ?? null
        );
    }

    /**
     * Send training overdue notification
     */
    public function notifyTrainingOverdue(
        string $organizationId,
        int $employeeId,
        array $overdueData
    ): Notification {
        return $this->create(
            $organizationId,
            $employeeId,
            'training_overdue',
            'Training Overdue',
            "{$overdueData['training_title']} was due on {$overdueData['due_date']}. Please complete it soon.",
            [
                'training_item_id' => $overdueData['training_item_id'],
                'record_id' => $overdueData['record_id'],
                'due_date' => $overdueData['due_date'],
            ],
            'EmployeeTrainingRecord',
            $overdueData['record_id'] ?? null
        );
    }
}
