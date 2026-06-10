<?php

namespace App\Http\Controllers\Goals;

use App\Http\Controllers\Controller;
use App\Models\Goal;
use App\Models\GoalAssignment;
use App\Models\GoalKeyResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoalController extends Controller
{
    private function orgId(): int
    {
        return app('current.organization_id');
    }

    // ── List goals / objectives ───────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $orgId  = $this->orgId();
        $isOkr  = filter_var($request->query('okr', 'false'), FILTER_VALIDATE_BOOLEAN);
        $view   = $request->query('view', 'list'); // list | mine | library
        $status = $request->query('status', 'all');

        $query = Goal::with([
                'assignees:id,first_name,last_name,employee_number,designation_id',
                'assignees.designation:id,title',
                'createdBy:id,first_name,last_name',
                'keyResults',
                'subGoals.assignees:id,first_name,last_name',
            ])
            ->where('organization_id', $orgId)
            ->where('is_okr', $isOkr)
            ->whereNull('parent_goal_id')
            ->orderByDesc('created_at');

        if ($view === 'mine') {
            $empId = $request->user()?->employee?->id;
            if ($empId) {
                $query->whereHas('assignments', fn($q) => $q->where('employee_id', $empId));
            }
        }

        if ($view === 'library') {
            $query->where('in_library', true);
        }

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $goals = $query->get();

        // Status counts (same scope minus status filter)
        $countBase = Goal::where('organization_id', $orgId)
            ->where('is_okr', $isOkr)
            ->whereNull('parent_goal_id');

        if ($view === 'mine') {
            $empId = $request->user()?->employee?->id;
            if ($empId) {
                $countBase->whereHas('assignments', fn($q) => $q->where('employee_id', $empId));
            }
        }
        if ($view === 'library') {
            $countBase->where('in_library', true);
        }

        $counts = $countBase->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        return response()->json([
            'data'   => $goals,
            'counts' => [
                'all'          => $counts->sum(),
                'pending'      => $counts->get('pending', 0),
                'in_progress'  => $counts->get('in_progress', 0),
                'achieved'     => $counts->get('achieved', 0),
                'not_achieved' => $counts->get('not_achieved', 0),
                'on_hold'      => $counts->get('on_hold', 0),
            ],
        ]);
    }

    // ── Show single goal ──────────────────────────────────────────────────────
    public function show(Goal $goal): JsonResponse
    {
        $this->authorizeOrg($goal);
        $goal->load([
            'assignees:id,first_name,last_name,employee_number,designation_id',
            'assignees.designation:id,title',
            'createdBy:id,first_name,last_name',
            'parent:id,title',
            'subGoals.assignees:id,first_name,last_name',
            'keyResults',
        ]);
        return response()->json($goal);
    }

    // ── Create ────────────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'          => 'required|string|max:255',
            'description'    => 'nullable|string',
            'level'          => 'required|in:functional_unit,department,business_unit,practice,individual,organizational',
            'priority'       => 'required|in:low,medium,high,critical',
            'status'         => 'nullable|in:pending,in_progress,achieved,not_achieved,on_hold',
            'weight'         => 'nullable|numeric|min:0|max:100',
            'due_date'       => 'nullable|date',
            'completion'     => 'nullable|numeric|min:0',
            'is_okr'         => 'nullable|boolean',
            'in_library'     => 'nullable|boolean',
            'parent_goal_id' => 'nullable|exists:goals,id',
            'employee_ids'   => 'nullable|array',
            'employee_ids.*' => 'exists:employees,id',
        ]);

        $data['organization_id'] = $this->orgId();
        $data['created_by_id']   = $request->user()?->employee?->id;

        if (!isset($data['status'])) {
            $data['status'] = Goal::statusFromCompletion((float) ($data['completion'] ?? 0));
        }

        $goal = Goal::create($data);

        foreach (($data['employee_ids'] ?? []) as $empId) {
            GoalAssignment::firstOrCreate(['goal_id' => $goal->id, 'employee_id' => $empId]);
        }

        $goal->load(['assignees:id,first_name,last_name,designation_id', 'assignees.designation:id,title', 'keyResults']);
        return response()->json($goal, 201);
    }

    // ── Update ────────────────────────────────────────────────────────────────
    public function update(Request $request, Goal $goal): JsonResponse
    {
        $this->authorizeOrg($goal);

        $data = $request->validate([
            'title'          => 'sometimes|string|max:255',
            'description'    => 'nullable|string',
            'level'          => 'sometimes|in:functional_unit,department,business_unit,practice,individual,organizational',
            'priority'       => 'sometimes|in:low,medium,high,critical',
            'status'         => 'sometimes|in:pending,in_progress,achieved,not_achieved,on_hold',
            'weight'         => 'nullable|numeric|min:0|max:100',
            'due_date'       => 'nullable|date',
            'completion'     => 'nullable|numeric|min:0',
            'is_okr'         => 'nullable|boolean',
            'in_library'     => 'nullable|boolean',
            'parent_goal_id' => 'nullable|exists:goals,id',
            'employee_ids'   => 'nullable|array',
            'employee_ids.*' => 'exists:employees,id',
        ]);

        if (isset($data['completion']) && !isset($data['status'])) {
            $data['status'] = Goal::statusFromCompletion((float) $data['completion']);
        }

        $goal->update($data);

        if (array_key_exists('employee_ids', $data)) {
            GoalAssignment::where('goal_id', $goal->id)->delete();
            foreach (($data['employee_ids'] ?? []) as $empId) {
                GoalAssignment::create(['goal_id' => $goal->id, 'employee_id' => $empId]);
            }
        }

        $goal->load(['assignees:id,first_name,last_name,designation_id', 'assignees.designation:id,title', 'keyResults']);
        return response()->json($goal);
    }

    // ── Delete ────────────────────────────────────────────────────────────────
    public function destroy(Goal $goal): JsonResponse
    {
        $this->authorizeOrg($goal);
        $goal->delete();
        return response()->json(null, 204);
    }

    // ── Inline completion update ──────────────────────────────────────────────
    public function updateCompletion(Request $request, Goal $goal): JsonResponse
    {
        $this->authorizeOrg($goal);
        $data       = $request->validate(['completion' => 'required|numeric|min:0']);
        $completion = (float) $data['completion'];
        $status     = Goal::statusFromCompletion($completion);
        $goal->update(['completion' => $completion, 'status' => $status]);
        return response()->json(['completion' => $goal->completion, 'status' => $goal->status]);
    }

    // ── Inline status update ──────────────────────────────────────────────────
    public function updateStatus(Request $request, Goal $goal): JsonResponse
    {
        $this->authorizeOrg($goal);
        $data = $request->validate(['status' => 'required|in:pending,in_progress,achieved,not_achieved,on_hold']);
        $goal->update(['status' => $data['status']]);
        return response()->json(['status' => $goal->status]);
    }

    // ── Key Results ───────────────────────────────────────────────────────────
    public function storeKeyResult(Request $request, Goal $goal): JsonResponse
    {
        $this->authorizeOrg($goal);

        $data = $request->validate([
            'title'         => 'required|string|max:255',
            'description'   => 'nullable|string',
            'use_metric'    => 'nullable|boolean',
            'metric_format' => 'nullable|string|in:number,percentage,currency',
            'metric_unit'   => 'nullable|string|max:50',
            'start_value'   => 'nullable|numeric',
            'target_value'  => 'nullable|numeric',
            'current_value' => 'nullable|numeric',
            'completion'    => 'nullable|numeric|min:0',
            'weight'        => 'nullable|numeric|min:0',
            'due_date'      => 'nullable|date',
            'status'        => 'nullable|in:pending,in_progress,achieved,not_achieved,on_hold',
        ]);

        $data['goal_id'] = $goal->id;
        $kr = GoalKeyResult::create($data);

        if ($kr->use_metric) {
            $kr->update(['completion' => $kr->computeCompletion()]);
        }

        return response()->json($kr, 201);
    }

    public function updateKeyResult(Request $request, GoalKeyResult $keyResult): JsonResponse
    {
        $this->authorizeOrg($keyResult->goal);
        $data = $request->validate([
            'title'         => 'sometimes|string|max:255',
            'description'   => 'nullable|string',
            'use_metric'    => 'nullable|boolean',
            'metric_format' => 'nullable|string|in:number,percentage,currency',
            'metric_unit'   => 'nullable|string|max:50',
            'start_value'   => 'nullable|numeric',
            'target_value'  => 'nullable|numeric',
            'current_value' => 'nullable|numeric',
            'completion'    => 'nullable|numeric|min:0',
            'weight'        => 'nullable|numeric|min:0',
            'due_date'      => 'nullable|date',
            'status'        => 'nullable|in:pending,in_progress,achieved,not_achieved,on_hold',
        ]);

        $keyResult->update($data);

        if ($keyResult->use_metric) {
            $keyResult->update(['completion' => $keyResult->computeCompletion()]);
        }

        return response()->json($keyResult);
    }

    public function destroyKeyResult(GoalKeyResult $keyResult): JsonResponse
    {
        $this->authorizeOrg($keyResult->goal);
        $keyResult->delete();
        return response()->json(null, 204);
    }

    private function authorizeOrg(Goal $goal): void
    {
        if ($goal->organization_id !== $this->orgId()) abort(403);
    }
}
