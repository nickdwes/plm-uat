<?php

namespace App\Services\Training;

use App\Services\Task\TaskGenerator;
use App\Models\Employee;
use App\Models\Training\TrainingAssignment;
use App\Models\Training\TrainingItem;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TrainingAssignmentService
{
    protected TrainingRecordService $recordService;
    protected NotificationService $notificationService;

    public function __construct(
        TrainingRecordService $recordService,
        NotificationService $notificationService,
        private TaskGenerator $tasks,
    ) {
        $this->recordService = $recordService;
        $this->notificationService = $notificationService;
    }

    /**
     * Create assignments for bulk approval workflow
     */
    public function bulkCreateForApproval(
        string $organizationId,
        string $trainingItemId,
        array $employeeIds,
        ?string $dueDate = null,
        string $assignmentType = 'mandatory',
        int $assignedByUserId = null
    ): array {
        $trainingItem = TrainingItem::findOrFail($trainingItemId);
        $createdCount = 0;
        $skipCount = 0;
        $noManagerCount = 0;

        foreach ($employeeIds as $employeeId) {
            $employee = Employee::findOrFail($employeeId);

            // Check for existing assignment or enrollment
            $existing = TrainingAssignment::where('organization_id', $organizationId)
                ->where('training_item_id', $trainingItemId)
                ->where('employee_id', $employeeId)
                ->where('status', '!=', 'rejected')
                ->first();

            if ($existing) {
                $skipCount++;
                continue;
            }

            // Get employee's manager
            $manager = $employee->manager;
            if (!$manager) {
                $noManagerCount++;
                // If no manager, auto-enroll with status=approved
                $this->recordService->enroll(
                    $employeeId,
                    $trainingItemId,
                    $assignmentType
                );
                $createdCount++;
                continue;
            }

            // Create pending assignment
            $assignment = TrainingAssignment::create([
                'organization_id' => $organizationId,
                'training_item_id' => $trainingItemId,
                'employee_id' => $employeeId,
                'assigned_by_user_id' => $assignedByUserId ?? auth()->id(),
                'manager_id' => $manager->user_id,
                'status' => 'pending',
                'due_date' => $dueDate ? Carbon::parse($dueDate)->toDateString() : null,
                'assignment_type' => $assignmentType,
            ]);

            // Send notification to manager
            $this->notificationService->notifyTrainingAssignment(
                $organizationId,
                $manager,
                [
                    'employee_name' => "{$employee->first_name} {$employee->last_name}",
                    'training_title' => $trainingItem->title,
                    'training_item_id' => $trainingItemId,
                    'employee_id' => $employeeId,
                    'assignment_type' => $assignmentType,
                    'due_date' => $dueDate,
                    'assignment_id' => $assignment->id,
                ]
            );

            $createdCount++;
        }

        return [
            'created' => $createdCount,
            'already_assigned' => $skipCount,
            'no_manager_auto_enrolled' => $noManagerCount,
        ];
    }

    /**
     * Get pending assignments for a manager
     */
    public function getPendingForManager(int $managerId, string $organizationId, int $perPage = 20)
    {
        return TrainingAssignment::byOrganization($organizationId)
            ->pendingForManager($managerId)
            ->with(['trainingItem', 'employee', 'assignedByUser', 'manager'])
            ->paginate($perPage);
    }

    /**
     * Approve an assignment
     */
    public function approve(string $assignmentId, string $organizationId, ?string $notes = null): array
    {
        $assignment = TrainingAssignment::findOrFail($assignmentId);

        if ($assignment->organization_id !== $organizationId) {
            throw new \Exception('Unauthorized');
        }

        if ($assignment->status !== 'pending') {
            throw new \Exception('Assignment is no longer pending');
        }

        // Approve the assignment
        $assignment->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approval_notes' => $notes,
        ]);

        // Create training record (enroll the learner)
        $record = $this->recordService->enroll(
            $assignment->employee_id,
            $assignment->training_item_id,
            $assignment->assignment_type,
            $assignment->due_date ? Carbon::parse($assignment->due_date) : null
        );

        /*
         * Approved training becomes a task on the learner's list.
         *
         * An enrolment nobody has to do anything about is how a due date passes
         * unnoticed — the legacy raised 50 Training and 41 certificate tasks
         * for exactly this reason. Keyed to the assignment, so approving twice
         * or replaying the call raises one task, not two.
         */
        $this->tasks->once(
            (int) $assignment->organization_id,
            'training_assignment',
            (string) $assignment->id,
            'training',
            [
                'title'       => 'Complete training: ' . ($assignment->trainingItem->title ?? 'assigned course'),
                'category'    => 'training',
                'assigned_to' => $assignment->employee_id,
                'deadline'    => $assignment->due_date?->toDateString(),
                'description' => $notes,
            ],
        );

        return [
            'assignment_id' => $assignment->id,
            'record_id' => $record->id,
            'status' => 'approved',
            'message' => 'Assignment approved. Learner has been enrolled.',
        ];
    }

    /**
     * Reject an assignment
     */
    public function reject(string $assignmentId, string $organizationId, string $rejectionReason): array
    {
        $assignment = TrainingAssignment::findOrFail($assignmentId);

        if ($assignment->organization_id !== $organizationId) {
            throw new \Exception('Unauthorized');
        }

        if ($assignment->status !== 'pending') {
            throw new \Exception('Assignment is no longer pending');
        }

        $assignment->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejection_reason' => $rejectionReason,
        ]);

        return [
            'assignment_id' => $assignment->id,
            'status' => 'rejected',
            'message' => 'Assignment rejected.',
        ];
    }

    /**
     * Withdraw an assignment
     */
    public function withdraw(string $assignmentId, string $organizationId, ?string $reason = null): array
    {
        $assignment = TrainingAssignment::findOrFail($assignmentId);

        if ($assignment->organization_id !== $organizationId) {
            throw new \Exception('Unauthorized');
        }

        $assignment->update([
            'status' => 'withdrawn',
            'withdrawn_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return [
            'assignment_id' => $assignment->id,
            'status' => 'withdrawn',
            'message' => 'Assignment withdrawn.',
        ];
    }

    /**
     * Get assignment stats
     */
    public function getStats(string $organizationId, ?int $managerId = null): array
    {
        $query = TrainingAssignment::byOrganization($organizationId);

        if ($managerId) {
            $query->where('manager_id', $managerId);
        }

        return [
            'pending' => $query->where('status', 'pending')->count(),
            'approved' => $query->where('status', 'approved')->count(),
            'rejected' => $query->where('status', 'rejected')->count(),
            'withdrawn' => $query->where('status', 'withdrawn')->count(),
        ];
    }
}
