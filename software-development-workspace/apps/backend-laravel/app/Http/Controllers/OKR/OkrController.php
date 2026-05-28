<?php

namespace App\Http\Controllers\OKR;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\OkrCycle;
use App\Models\OkrKeyResult;
use App\Models\OkrKrDeptScore;
use App\Models\OkrObjective;
use App\Services\OKR\OkrScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OkrController extends Controller
{
    public function __construct(private OkrScoringService $scoring) {}

    // ── Cycles ────────────────────────────────────────────────────────────────

    public function indexCycles(): JsonResponse
    {
        $orgId  = app('current.organization_id');
        $cycles = OkrCycle::where('organization_id', $orgId)
            ->withCount('objectives')
            ->orderByDesc('year')
            ->get();
        return response()->json(['data' => $cycles]);
    }

    public function storeCycle(Request $request): JsonResponse
    {
        $orgId = app('current.organization_id');
        $data  = $request->validate([
            'name'   => 'required|string|max:120',
            'year'   => 'required|integer|min:2020|max:2100',
            'period' => 'required|in:annual,q1,q2,q3,q4',
            'status' => 'in:draft,active,closed',
        ]);
        $cycle = OkrCycle::create(['organization_id' => $orgId] + $data);
        return response()->json($cycle, 201);
    }

    /** Full scorecard: cycle + objectives + KRs + dept scores + computed scores */
    public function showCycle(OkrCycle $okrCycle): JsonResponse
    {
        $orgId = app('current.organization_id');
        if ($okrCycle->organization_id !== $orgId) abort(403);

        // Get all departments for this org (for matrix column list)
        $departments = Department::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $scorecard = $this->scoring->computeCycleScorecard($okrCycle);

        return response()->json([
            'cycle'       => $okrCycle,
            'departments' => $departments,
            'objectives'  => $scorecard,
        ]);
    }

    public function updateCycle(Request $request, OkrCycle $okrCycle): JsonResponse
    {
        $orgId = app('current.organization_id');
        if ($okrCycle->organization_id !== $orgId) abort(403);

        $data = $request->validate([
            'name'   => 'sometimes|string|max:120',
            'year'   => 'sometimes|integer|min:2020|max:2100',
            'period' => 'sometimes|in:annual,q1,q2,q3,q4',
            'status' => 'sometimes|in:draft,active,closed',
        ]);
        $okrCycle->update($data);
        return response()->json($okrCycle);
    }

    // ── Objectives ────────────────────────────────────────────────────────────

    public function storeObjective(Request $request, OkrCycle $okrCycle): JsonResponse
    {
        $orgId = app('current.organization_id');
        if ($okrCycle->organization_id !== $orgId) abort(403);

        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'weight'      => 'numeric|min:0|max:100',
            'sort_order'  => 'integer|min:0',
        ]);

        $obj = OkrObjective::create([
            'cycle_id'        => $okrCycle->id,
            'organization_id' => $orgId,
            'title'           => $data['title'],
            'description'     => $data['description'] ?? null,
            'weight'          => $data['weight'] ?? 1,
            'sort_order'      => $data['sort_order'] ?? 0,
        ]);

        return response()->json($obj, 201);
    }

    public function updateObjective(Request $request, OkrObjective $okrObjective): JsonResponse
    {
        $orgId = app('current.organization_id');
        if ($okrObjective->organization_id !== $orgId) abort(403);

        $data = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'weight'      => 'sometimes|numeric|min:0|max:100',
            'sort_order'  => 'sometimes|integer|min:0',
        ]);
        $okrObjective->update($data);
        return response()->json($okrObjective);
    }

    public function destroyObjective(OkrObjective $okrObjective): JsonResponse
    {
        $orgId = app('current.organization_id');
        if ($okrObjective->organization_id !== $orgId) abort(403);
        $okrObjective->delete();
        return response()->json(null, 204);
    }

    // ── Key Results ───────────────────────────────────────────────────────────

    public function storeKeyResult(Request $request, OkrObjective $okrObjective): JsonResponse
    {
        $orgId = app('current.organization_id');
        if ($okrObjective->organization_id !== $orgId) abort(403);

        $data = $request->validate([
            'title'        => 'required|string|max:255',
            'measure'      => 'nullable|string|max:255',
            'target_value' => 'numeric|min:0',
            'achievement'  => 'nullable|numeric|min:0',
            'weight'       => 'numeric|min:0|max:100',
            'sort_order'   => 'integer|min:0',
        ]);

        $kr = OkrKeyResult::create([
            'objective_id' => $okrObjective->id,
            'title'        => $data['title'],
            'measure'      => $data['measure'] ?? null,
            'target_value' => $data['target_value'] ?? 100,
            'achievement'  => $data['achievement'] ?? null,
            'weight'       => $data['weight'] ?? 1,
            'sort_order'   => $data['sort_order'] ?? 0,
            'score_percent' => 0,
        ]);

        // Compute initial score if achievement provided
        if ($kr->achievement !== null) {
            $this->scoring->recomputeAndPersist($kr);
        }

        return response()->json($kr, 201);
    }

    public function updateKeyResult(Request $request, OkrKeyResult $okrKeyResult): JsonResponse
    {
        $orgId = app('current.organization_id');
        if ($okrKeyResult->objective->organization_id !== $orgId) abort(403);

        $data = $request->validate([
            'title'        => 'sometimes|string|max:255',
            'measure'      => 'nullable|string|max:255',
            'target_value' => 'sometimes|numeric|min:0',
            'weight'       => 'sometimes|numeric|min:0|max:100',
            'sort_order'   => 'sometimes|integer|min:0',
        ]);
        $okrKeyResult->update($data);
        return response()->json($okrKeyResult);
    }

    public function destroyKeyResult(OkrKeyResult $okrKeyResult): JsonResponse
    {
        $orgId = app('current.organization_id');
        if ($okrKeyResult->objective->organization_id !== $orgId) abort(403);
        $okrKeyResult->delete();
        return response()->json(null, 204);
    }

    /** Update achievement value → recompute score */
    public function updateAchievement(Request $request, OkrKeyResult $okrKeyResult): JsonResponse
    {
        $orgId = app('current.organization_id');
        if ($okrKeyResult->objective->organization_id !== $orgId) abort(403);

        $data = $request->validate([
            'achievement' => 'required|numeric|min:0',
        ]);

        $okrKeyResult->update(['achievement' => $data['achievement']]);
        $score = $this->scoring->recomputeAndPersist($okrKeyResult->fresh()->load('deptScores'));

        return response()->json([
            'id'            => $okrKeyResult->id,
            'achievement'   => (float) $data['achievement'],
            'score_percent' => $score,
        ]);
    }

    /** Bulk upsert department scores for a KR → recompute score */
    public function upsertDeptScores(Request $request, OkrKeyResult $okrKeyResult): JsonResponse
    {
        $orgId = app('current.organization_id');
        if ($okrKeyResult->objective->organization_id !== $orgId) abort(403);

        $data = $request->validate([
            'scores'              => 'required|array|min:1',
            'scores.*.department_id' => 'required|integer|exists:departments,id',
            'scores.*.score'      => 'required|numeric|min:0|max:100',
            'scores.*.weight'     => 'numeric|min:0|max:100',
        ]);

        foreach ($data['scores'] as $row) {
            OkrKrDeptScore::updateOrCreate(
                ['key_result_id' => $okrKeyResult->id, 'department_id' => $row['department_id']],
                ['score' => $row['score'], 'weight' => $row['weight'] ?? 1]
            );
        }

        $score = $this->scoring->recomputeAndPersist($okrKeyResult->fresh()->load('deptScores'));

        return response()->json([
            'id'            => $okrKeyResult->id,
            'score_percent' => $score,
            'dept_scores'   => $okrKeyResult->fresh()->deptScores()->with('department')->get()->map(fn($ds) => [
                'department_id'   => $ds->department_id,
                'department_name' => $ds->department->name,
                'score'           => (float) $ds->score,
                'weight'          => (float) $ds->weight,
            ]),
        ]);
    }
}
