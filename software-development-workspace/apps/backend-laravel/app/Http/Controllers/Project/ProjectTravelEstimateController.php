<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Services\Project\TravelEstimator;
use Illuminate\Http\JsonResponse;

/**
 * The rate-card travel estimate. It prices each travel cycle from the
 * allowance rate card (per-diem / accommodation / incidentals) plus standard
 * per-mode transport, and can write those figures onto the matching travel
 * requests — where they then feed budget generation. The estimate is a
 * suggestion; the register value stays editable by hand.
 */
class ProjectTravelEstimateController extends Controller
{
    public function index(Project $project, TravelEstimator $estimator): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($estimator->forProject($project));
    }

    /**
     * Write the estimate onto each travel request that came from a cycle and
     * has not been costed by hand yet (a manually set cost is left alone).
     */
    public function apply(Project $project, TravelEstimator $estimator): JsonResponse
    {
        $this->authorize('update', $project);

        $estimate = $estimator->forProject($project);
        $byKey = collect($estimate['cycles'])->keyBy('cycle_key');

        $applied = 0;
        foreach ($project->travelRequests()->whereNull('estimated_cost')->get() as $req) {
            $cycle = $byKey->get($req->cycle_key);
            if (! $cycle) {
                continue;
            }
            $req->update(['estimated_cost' => $cycle['total'], 'currency' => $estimate['rates']['currency']]);
            $applied++;
        }

        return response()->json([
            'applied'  => $applied,
            'estimate' => $estimate,
        ]);
    }
}
