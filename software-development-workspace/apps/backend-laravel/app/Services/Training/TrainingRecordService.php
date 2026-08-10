<?php

namespace App\Services\Training;

use App\Support\Storage\DocumentVault;
use App\Models\Employee;
use App\Models\Training\DailyCheckIn;
use App\Models\Training\EmployeeTrainingRecord;
use App\Models\Training\ProgressUpdate;
use App\Models\Training\TrainingEvidenceFile;
use App\Models\Training\TrainingItem;
use App\Repositories\Training\TrainingRecordRepository;
use App\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TrainingRecordService extends BaseService
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf', 'image/jpeg', 'image/png', 'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    private const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB

    public function __construct(TrainingRecordRepository $repository)
    {
        parent::__construct($repository);
    }

    // ── List records (for HR / manager) ───────────────────────────────────────

    public function list(int $organizationId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = EmployeeTrainingRecord::with(['employee', 'trainingItem'])
            ->orderByDesc('created_at');

        if (!empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }

        if (!empty($filters['training_item_id'])) {
            $query->where('training_item_id', $filters['training_item_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['assignment_type'])) {
            $query->where('assignment_type', $filters['assignment_type']);
        }

        if (!empty($filters['is_priority'])) {
            $query->where('is_priority', true);
        }

        return $query->paginate($perPage);
    }

    // ── List records for a specific employee (My Training) ────────────────────

    public function listForEmployee(int $employeeId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = EmployeeTrainingRecord::with(['trainingItem.competencies', 'evidenceFiles'])
            ->where('employee_id', $employeeId)
            ->orderByDesc('created_at');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['assignment_type'])) {
            $query->where('assignment_type', $filters['assignment_type']);
        }

        return $query->paginate($perPage);
    }

    // ── Self-enroll ────────────────────────────────────────────────────────────

    public function enroll(int $organizationId, int $employeeId, int $trainingItemId, int $userId): EmployeeTrainingRecord
    {
        // Check for duplicate enrollment
        $existing = EmployeeTrainingRecord::where('employee_id', $employeeId)
            ->where('training_item_id', $trainingItemId)
            ->first();

        if ($existing) {
            throw new \DomainException('Employee is already enrolled in this training item.', 1001);
        }

        $record = EmployeeTrainingRecord::create([
            'organization_id'  => $organizationId,
            'employee_id'      => $employeeId,
            'training_item_id' => $trainingItemId,
            'assignment_type'  => 'self_assigned',
            'status'           => 'enrolled',
            'progress_percent' => 0,
            'created_by'       => $userId,
        ]);

        return $record->load(['trainingItem', 'employee']);
    }

    // ── Log progress (never decreases) ────────────────────────────────────────

    public function logProgress(
        EmployeeTrainingRecord $record,
        int $progressPercent,
        ?string $notes,
        int $employeeId
    ): EmployeeTrainingRecord {
        // Progress can never decrease
        if ($progressPercent <= $record->progress_percent) {
            return $record;
        }

        DB::transaction(function () use ($record, $progressPercent, $notes, $employeeId) {
            ProgressUpdate::create([
                'training_record_id' => $record->id,
                'employee_id'        => $employeeId,
                'progress_percent'   => $progressPercent,
                'notes'              => $notes,
                'recorded_at'        => now(),
            ]);

            $update = ['progress_percent' => $progressPercent];

            if ($record->status === 'enrolled' && $progressPercent > 0) {
                $update['status']     = 'in_progress';
                $update['started_at'] = now();
            }

            if ($progressPercent >= 100) {
                $update['status']       = 'completed';
                $update['completed_at'] = now();
            }

            $record->update($update);
        });

        return $record->fresh(['trainingItem', 'progressUpdates', 'evidenceFiles']);
    }

    // ── Daily check-in (long courses only) ────────────────────────────────────

    public function checkIn(
        EmployeeTrainingRecord $record,
        int $employeeId,
        string $checkInDate,
        ?string $notes,
        int $hoursSpent
    ): DailyCheckIn {
        $checkIn = DailyCheckIn::updateOrCreate(
            [
                'training_record_id' => $record->id,
                'check_in_date'      => $checkInDate,
            ],
            [
                'employee_id'  => $employeeId,
                'notes'        => $notes,
                'hours_spent'  => $hoursSpent,
            ]
        );

        // Auto-transition to in_progress if still enrolled
        if ($record->status === 'enrolled') {
            $record->update(['status' => 'in_progress', 'started_at' => now()]);
        }

        return $checkIn;
    }

    // ── Upload evidence file ───────────────────────────────────────────────────

    public function uploadEvidence(
        EmployeeTrainingRecord $record,
        int $employeeId,
        int $uploadedBy,
        string $category,
        UploadedFile $file
    ): TrainingEvidenceFile {
        $ext  = $file->getClientOriginalExtension();
        $path = sprintf(
            'training/%d/records/%d/%s.%s',
            $record->organization_id,
            $record->id,
            Str::uuid(),
            $ext
        );

        // Encrypted like every other class. Evidence for a training claim is
        // often a medical note or an exam result with a name on it.
        DocumentVault::putContents('training_evidence', $path, $file->getContent());

        return TrainingEvidenceFile::create([
            'training_record_id' => $record->id,
            'employee_id'        => $employeeId,
            'uploaded_by'        => $uploadedBy,
            'category'           => $category,
            'original_name'      => $file->getClientOriginalName(),
            'storage_path'       => $path,
            'mime_type'          => $file->getMimeType(),
            'size_bytes'         => $file->getSize(),
        ]);
    }

    // ── Mark as priority ───────────────────────────────────────────────────────

    public function setPriority(EmployeeTrainingRecord $record, bool $isPriority, ?string $reason): EmployeeTrainingRecord
    {
        $record->update(['is_priority' => $isPriority, 'priority_reason' => $reason]);
        return $record->fresh();
    }

    // ── Withdraw ───────────────────────────────────────────────────────────────

    public function withdraw(EmployeeTrainingRecord $record): EmployeeTrainingRecord
    {
        $record->update(['status' => 'withdrawn']);
        return $record->fresh();
    }

    // ── Bulk-assign (HR) ───────────────────────────────────────────────────────

    /**
     * Assign a training item to multiple employees.
     *
     * $filters can contain: employee_ids (array), department_id, or leave empty
     * to target all employees in the organisation.
     *
     * Skips employees already enrolled. Returns counts: enrolled, skipped.
     */
    public function bulkAssign(
        int $organizationId,
        int $trainingItemId,
        array $employeeIds,
        ?string $dueDate,
        int $userId
    ): array {
        $enrolled = 0;
        $skipped  = 0;

        $alreadyEnrolled = EmployeeTrainingRecord::where('training_item_id', $trainingItemId)
            ->whereIn('employee_id', $employeeIds)
            ->pluck('employee_id')
            ->flip();

        foreach ($employeeIds as $empId) {
            if ($alreadyEnrolled->has($empId)) {
                $skipped++;
                continue;
            }

            EmployeeTrainingRecord::create([
                'organization_id'  => $organizationId,
                'employee_id'      => $empId,
                'training_item_id' => $trainingItemId,
                'assignment_type'  => 'mandatory',
                'status'           => 'enrolled',
                'progress_percent' => 0,
                'due_date'         => $dueDate,
                'created_by'       => $userId,
            ]);

            $enrolled++;
        }

        return ['enrolled' => $enrolled, 'skipped' => $skipped];
    }
}
