<?php

namespace App\Http\Controllers\Training;

use App\Http\Requests\Training\ApproveAssignmentRequest;
use App\Http\Requests\Training\BulkAssignTrainingRequest;
use App\Http\Requests\Training\RejectAssignmentRequest;
use App\Http\Resources\Training\TrainingAssignmentResource;
use App\Models\Training\TrainingAssignment;
use App\Services\Training\TrainingAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainingAssignmentController
{
    protected TrainingAssignmentService $service;

    public function __construct(TrainingAssignmentService $service)
    {
        $this->service = $service;
    }

    /**
     * Create bulk training assignments for manager approval
     */
    public function bulkCreate(BulkAssignTrainingRequest $request): JsonResponse
    {
        $organizationId = app('organizationId');
        $validated = $request->validated();

        $result = $this->service->bulkCreateForApproval(
            $organizationId,
            $validated['training_item_id'],
            $validated['employee_ids'],
            $validated['due_date'] ?? null,
            $validated['assignment_type'],
            auth()->id()
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Training assignments created successfully',
            'data' => $result,
        ], 201);
    }

    /**
     * Get pending assignments for the authenticated manager
     */
    public function myPending(Request $request): JsonResponse
    {
        $organizationId = app('organizationId');
        $user = auth()->user();
        $managerId = $user->id;

        $assignments = $this->service->getPendingForManager($managerId, $organizationId);

        return response()->json([
            'data' => TrainingAssignmentResource::collection($assignments),
            'pagination' => [
                'total' => $assignments->total(),
                'per_page' => $assignments->perPage(),
                'current_page' => $assignments->currentPage(),
                'last_page' => $assignments->lastPage(),
            ],
        ]);
    }

    /**
     * Get pending assignments (HR can see all pending for org)
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = app('organizationId');
        $perPage = $request->query('per_page', 20);

        $query = TrainingAssignment::byOrganization($organizationId)
            ->with(['trainingItem', 'employee', 'assignedByUser', 'manager']);

        // Filter by status
        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }

        // Filter by manager
        if ($request->query('manager_id')) {
            $query->where('manager_id', $request->query('manager_id'));
        }

        $assignments = $query->paginate($perPage);

        return response()->json([
            'data' => TrainingAssignmentResource::collection($assignments),
            'pagination' => [
                'total' => $assignments->total(),
                'per_page' => $assignments->perPage(),
                'current_page' => $assignments->currentPage(),
                'last_page' => $assignments->lastPage(),
            ],
        ]);
    }

    /**
     * Get a single assignment
     */
    public function show(string $id): JsonResponse
    {
        $organizationId = app('organizationId');

        $assignment = TrainingAssignment::byOrganization($organizationId)
            ->with(['trainingItem', 'employee', 'assignedByUser', 'manager'])
            ->findOrFail($id);

        return response()->json([
            'data' => new TrainingAssignmentResource($assignment),
        ]);
    }

    /**
     * Approve an assignment
     */
    public function approve(ApproveAssignmentRequest $request, string $id): JsonResponse
    {
        $organizationId = app('organizationId');

        $result = $this->service->approve($id, $organizationId, $request->validated('notes'));

        return response()->json($result, 200);
    }

    /**
     * Reject an assignment
     */
    public function reject(RejectAssignmentRequest $request, string $id): JsonResponse
    {
        $organizationId = app('organizationId');

        $result = $this->service->reject($id, $organizationId, $request->validated('reason'));

        return response()->json($result, 200);
    }

    /**
     * Get assignment statistics
     */
    public function stats(Request $request): JsonResponse
    {
        $organizationId = app('organizationId');
        $managerId = $request->query('manager_id');

        $stats = $this->service->getStats($organizationId, $managerId);

        return response()->json(['data' => $stats]);
    }
}
