<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Services\Project\TravelCycleBuilder;
use Illuminate\Http\JsonResponse;

/**
 * The project's travel schedule — task-level travel consolidated into the most
 * cost-effective cycles. The consolidation lives in TravelCycleBuilder, shared
 * with the travel-request register.
 */
class ProjectTravelScheduleController extends Controller
{
    public function index(Project $project, TravelCycleBuilder $builder): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['cycles' => $builder->build($project)]);
    }
}
