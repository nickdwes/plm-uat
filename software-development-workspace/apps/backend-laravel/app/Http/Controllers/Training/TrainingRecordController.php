<?php

namespace App\Http\Controllers\Training;

use App\Support\Storage\DocumentVault;
use App\Http\Controllers\Controller;
use App\Http\Requests\Training\EnrollTrainingRequest;
use App\Http\Requests\Training\LogProgressRequest;
use App\Http\Resources\Training\EvidenceFileResource;
use App\Http\Resources\Training\TrainingRecordResource;
use App\Models\Training\DailyCheckIn;
use App\Models\Training\EmployeeTrainingRecord;
use App\Services\Training\TrainingRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class TrainingRecordController extends Controller
{
    public function __construct(private readonly TrainingRecordService $service) {}

    // ── HR/manager: list all records ──────────────────────────────────────────

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', EmployeeTrainingRecord::class);

        $records = $this->service->list(
            organizationId: app('current.organization_id'),
            filters: $request->only(['employee_id', 'training_item_id', 'status', 'assignment_type', 'is_priority']),
            perPage: (int) $request->input('per_page', 20),
        );

        return TrainingRecordResource::collection($records);
    }

    // ── Employee: my training ──────────────────────────────────────────────────

    public function myRecords(Request $request): AnonymousResourceCollection
    {
        $employee = $request->user()->employee;

        abort_unless($employee !== null, 422, 'No employee profile linked to this account.');

        $records = $this->service->listForEmployee(
            employeeId: $employee->id,
            filters: $request->only(['status', 'assignment_type']),
            perPage: (int) $request->input('per_page', 20),
        );

        return TrainingRecordResource::collection($records);
    }

    // ── Enroll (self or manager-assigned) ────────────────────────────────────

    public function store(EnrollTrainingRequest $request): TrainingRecordResource
    {
        $orgId    = app('current.organization_id');
        $user     = $request->user();
        $employee = $request->input('employee_id')
            ? \App\Models\Employee::findOrFail($request->input('employee_id'))
            : $user->employee;

        abort_unless($employee !== null, 422, 'No employee profile found.');

        $record = $this->service->enroll(
            organizationId: $orgId,
            employeeId: $employee->id,
            trainingItemId: $request->input('training_item_id'),
            userId: $user->id,
        );

        return new TrainingRecordResource($record);
    }

    // ── Show single record ────────────────────────────────────────────────────

    public function show(EmployeeTrainingRecord $trainingRecord): TrainingRecordResource
    {
        $this->authorize('view', $trainingRecord);

        return new TrainingRecordResource(
            $trainingRecord->load(['trainingItem.competencies', 'employee', 'progressUpdates', 'dailyCheckIns', 'evidenceFiles'])
        );
    }

    // ── Log progress ──────────────────────────────────────────────────────────

    public function logProgress(LogProgressRequest $request, EmployeeTrainingRecord $trainingRecord): TrainingRecordResource
    {
        $this->authorize('update', $trainingRecord);

        $employee = $request->user()->employee;
        abort_unless($employee !== null, 422, 'No employee profile linked to this account.');

        $record = $this->service->logProgress(
            record: $trainingRecord,
            progressPercent: $request->input('progress_percent'),
            notes: $request->input('notes'),
            employeeId: $employee->id,
        );

        return new TrainingRecordResource($record);
    }

    // ── Daily check-in ────────────────────────────────────────────────────────

    public function checkIn(Request $request, EmployeeTrainingRecord $trainingRecord): JsonResponse
    {
        $this->authorize('update', $trainingRecord);

        $request->validate([
            'check_in_date' => 'required|date|before_or_equal:today',
            'notes'         => 'nullable|string|max:1000',
            'hours_spent'   => 'nullable|integer|min:0|max:24',
        ]);

        $employee = $request->user()->employee;
        abort_unless($employee !== null, 422, 'No employee profile linked to this account.');

        $checkIn = $this->service->checkIn(
            record: $trainingRecord,
            employeeId: $employee->id,
            checkInDate: $request->input('check_in_date'),
            notes: $request->input('notes'),
            hoursSpent: (int) $request->input('hours_spent', 0),
        );

        return response()->json(['data' => [
            'id'            => $checkIn->id,
            'check_in_date' => $checkIn->check_in_date->toDateString(),
            'notes'         => $checkIn->notes,
            'hours_spent'   => $checkIn->hours_spent,
        ]]);
    }

    // ── Upload evidence ───────────────────────────────────────────────────────

    public function uploadEvidence(Request $request, EmployeeTrainingRecord $trainingRecord): EvidenceFileResource
    {
        $this->authorize('update', $trainingRecord);

        $request->validate([
            'file'     => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,webp,doc,docx',
            'category' => 'nullable|string|in:certificate,screenshot,other',
        ]);

        $employee = $request->user()->employee;
        abort_unless($employee !== null, 422, 'No employee profile linked to this account.');

        $file = $this->service->uploadEvidence(
            record: $trainingRecord,
            employeeId: $employee->id,
            uploadedBy: $request->user()->id,
            category: $request->input('category', 'certificate'),
            file: $request->file('file'),
        );

        return new EvidenceFileResource($file);
    }

    // ── Download evidence ─────────────────────────────────────────────────────

    public function downloadEvidence(EmployeeTrainingRecord $trainingRecord, int $evidenceFile): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('view', $trainingRecord);

        $file = $trainingRecord->evidenceFiles()->findOrFail($evidenceFile);

        abort_unless($file->fileExists(), 404, 'File not found.');

        // Through the vault, which decrypts.
        return DocumentVault::download(
            'training_evidence', $file->storage_path, $file->original_name,
            $file->mime_type ?? 'application/octet-stream',
        );
    }

    // ── Set priority ──────────────────────────────────────────────────────────

    public function setPriority(Request $request, EmployeeTrainingRecord $trainingRecord): TrainingRecordResource
    {
        $this->authorize('update', $trainingRecord);

        $request->validate([
            'is_priority'     => 'required|boolean',
            'priority_reason' => 'required_if:is_priority,true|nullable|string|min:10|max:500',
        ]);

        $record = $this->service->setPriority(
            record: $trainingRecord,
            isPriority: $request->boolean('is_priority'),
            reason: $request->input('priority_reason'),
        );

        return new TrainingRecordResource($record);
    }

    // ── Withdraw ──────────────────────────────────────────────────────────────

    public function withdraw(EmployeeTrainingRecord $trainingRecord): TrainingRecordResource
    {
        $this->authorize('update', $trainingRecord);
        return new TrainingRecordResource($this->service->withdraw($trainingRecord));
    }

    // ── Bulk assign (HR) ──────────────────────────────────────────────────────

    public function bulkAssign(Request $request): JsonResponse
    {
        $this->authorize('create', EmployeeTrainingRecord::class);

        $data = $request->validate([
            'training_item_id' => 'required|integer|exists:training_items,id',
            'employee_ids'     => 'required|array|min:1',
            'employee_ids.*'   => 'integer|exists:employees,id',
            'due_date'         => 'nullable|date|after_or_equal:today',
        ]);

        $result = $this->service->bulkAssign(
            organizationId: app('current.organization_id'),
            trainingItemId: $data['training_item_id'],
            employeeIds:    $data['employee_ids'],
            dueDate:        $data['due_date'] ?? null,
            userId:         $request->user()->id,
        );

        return response()->json([
            'message' => "Assigned to {$result['enrolled']} employee(s). {$result['skipped']} already enrolled.",
            'enrolled' => $result['enrolled'],
            'skipped'  => $result['skipped'],
        ]);
    }
}
