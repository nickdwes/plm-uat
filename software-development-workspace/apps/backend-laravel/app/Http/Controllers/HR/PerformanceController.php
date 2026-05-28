<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\TrainingRecord;
use App\Models\PromotionHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PerformanceController extends Controller
{
    // ── Performance Reviews ──────────────────────────────────────────────────

    public function reviews(Request $request): JsonResponse
    {
        $orgId = app('current.organization_id');

        $query = PerformanceReview::with(['employee:id,first_name,last_name,employee_number', 'reviewer:id,first_name,last_name'])
            ->whereHas('employee', fn($q) => $q->where('primary_organization_id', $orgId))
            ->orderByDesc('review_date');

        if ($eid = $request->query('employee_id')) {
            $query->where('employee_id', $eid);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function storeReview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id'  => 'required|exists:employees,id',
            'review_cycle' => 'required|string|max:60',
            'review_date'  => 'required|date',
            'rating'       => 'required|integer|min:1|max:5',
            'goals'        => 'nullable|string',
            'comments'     => 'nullable|string',
            'outcomes'     => 'nullable|string',
        ]);

        $review = PerformanceReview::create($data);
        return response()->json($review->load('employee:id,first_name,last_name'), 201);
    }

    public function updateReview(Request $request, PerformanceReview $performanceReview): JsonResponse
    {
        $data = $request->validate([
            'review_cycle' => 'sometimes|string|max:60',
            'review_date'  => 'sometimes|date',
            'rating'       => 'sometimes|integer|min:1|max:5',
            'goals'        => 'nullable|string',
            'comments'     => 'nullable|string',
            'outcomes'     => 'nullable|string',
        ]);
        $performanceReview->update($data);
        return response()->json($performanceReview);
    }

    public function destroyReview(PerformanceReview $performanceReview): JsonResponse
    {
        $performanceReview->delete();
        return response()->json(null, 204);
    }

    // ── Training Records ─────────────────────────────────────────────────────

    public function training(Request $request): JsonResponse
    {
        $orgId = app('current.organization_id');

        $query = TrainingRecord::with('employee:id,first_name,last_name,employee_number')
            ->whereHas('employee', fn($q) => $q->where('primary_organization_id', $orgId))
            ->orderByDesc('completion_date');

        if ($eid = $request->query('employee_id')) {
            $query->where('employee_id', $eid);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function storeTraining(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id'      => 'required|exists:employees,id',
            'course_name'      => 'required|string|max:200',
            'provider'         => 'nullable|string|max:200',
            'completion_date'  => 'required|date',
            'certification_no' => 'nullable|string|max:100',
            'notes'            => 'nullable|string',
        ]);
        $record = TrainingRecord::create($data);
        return response()->json($record, 201);
    }

    public function destroyTraining(TrainingRecord $trainingRecord): JsonResponse
    {
        $trainingRecord->delete();
        return response()->json(null, 204);
    }

    // ── Promotion History ────────────────────────────────────────────────────

    public function promotions(Request $request): JsonResponse
    {
        $orgId = app('current.organization_id');

        $query = PromotionHistory::with([
                'employee:id,first_name,last_name,employee_number',
                'fromDesignation:id,title',
                'toDesignation:id,title',
                'fromJobGrade:id,name',
                'toJobGrade:id,name',
            ])
            ->whereHas('employee', fn($q) => $q->where('primary_organization_id', $orgId))
            ->orderByDesc('effective_date');

        if ($eid = $request->query('employee_id')) {
            $query->where('employee_id', $eid);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function storePromotion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id'         => 'required|exists:employees,id',
            'from_designation_id' => 'nullable|exists:designations,id',
            'to_designation_id'   => 'nullable|exists:designations,id',
            'from_job_grade_id'   => 'nullable|exists:job_grades,id',
            'to_job_grade_id'     => 'nullable|exists:job_grades,id',
            'effective_date'      => 'required|date',
            'notes'               => 'nullable|string',
        ]);

        $data['recorded_by'] = $request->user()->email ?? 'system';

        $promotion = PromotionHistory::create($data);

        // Update employee's current designation/grade
        $emp = Employee::find($data['employee_id']);
        $updates = [];
        if (!empty($data['to_designation_id'])) $updates['designation_id'] = $data['to_designation_id'];
        if (!empty($data['to_job_grade_id']))   $updates['job_grade_id']   = $data['to_job_grade_id'];
        if ($updates) $emp->update($updates);

        return response()->json($promotion, 201);
    }
}
