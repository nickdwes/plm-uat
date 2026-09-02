<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Project\Project;
use App\Models\Task\TaskLogistics;
use App\Models\Task\TaskPrerequisite;
use App\Services\Project\TravelCycleBuilder;
use Illuminate\Http\JsonResponse;

/**
 * The project logistics summary — a single rollup of everything the logistics
 * data already says: what the tasks require, how far the prerequisites have
 * been cleared, and the shape of the travel, procurement and resourcing that
 * follows from them. One read, so a PM can see the whole logistics posture
 * without walking every register.
 */
class ProjectLogisticsSummaryController extends Controller
{
    public function index(Project $project, TravelCycleBuilder $builder): JsonResponse
    {
        $this->authorize('view', $project);

        $taskIds = $project->tasks()->pluck('id');

        return response()->json([
            'requirements'  => $this->requirements($taskIds),
            'prerequisites' => $this->prerequisites($project),
            'travel'        => $this->travel($project, $builder),
            'procurement'   => $this->procurement($project),
            'resourcing'    => $this->resourcing($project, $taskIds),
        ]);
    }

    /** How many tasks raise each logistics need. */
    private function requirements($taskIds): array
    {
        $logi = TaskLogistics::whereIn('task_id', $taskIds)->get();

        $out = ['tasks_with_needs' => $logi->filter(fn ($l) => collect(TaskLogistics::NEEDS)->contains(fn ($n) => $l->{$n}))->count()];
        foreach (TaskLogistics::NEEDS as $need) {
            $out[$need] = $logi->where($need, true)->count();
        }

        return $out;
    }

    /** Prerequisite progress — how much of the pre-work is cleared. */
    private function prerequisites(Project $project): array
    {
        $rows = TaskPrerequisite::where('project_id', $project->id)->get();

        return [
            'total'             => $rows->count(),
            'done'              => $rows->where('status', TaskPrerequisite::STATUS_DONE)->count(),
            'open'              => $rows->where('status', TaskPrerequisite::STATUS_OPEN)->count(),
            'in_progress'       => $rows->where('status', TaskPrerequisite::STATUS_IN_PROGRESS)->count(),
            'blocked'           => $rows->where('status', TaskPrerequisite::STATUS_BLOCKED)->count(),
            'procurement_needed'=> $rows->where('procurement_needed', true)->where('status', '!=', TaskPrerequisite::STATUS_DONE)->count(),
            'cleared_pct'       => $rows->count() ? (int) round($rows->where('status', TaskPrerequisite::STATUS_DONE)->count() / $rows->count() * 100) : null,
        ];
    }

    /** Travel consolidated into cycles. */
    private function travel(Project $project, TravelCycleBuilder $builder): array
    {
        $cycles = collect($builder->build($project));
        $requests = $project->travelRequests()->get();

        return [
            'cycles'          => $cycles->count(),
            'sites'           => $cycles->pluck('location')->filter()->unique()->count(),
            'travel_days'     => (int) $cycles->sum('travel_days'),
            'people'          => $cycles->flatMap(fn ($c) => $c['team'] ?? [])->unique()->count(),
            'requests'        => $requests->count(),
            'requests_pending'=> $requests->where('approval_status', 'pending')->count(),
        ];
    }

    /** Procurement posture. */
    private function procurement(Project $project): array
    {
        $rows = ProcurementRequest::where('project_id', $project->id)->get();

        return [
            'items'           => $rows->count(),
            'pending_approval'=> $rows->where('approval_status', 'pending')->count(),
            'long_lead'       => $rows->filter(fn ($r) => $r->isLongLead())->count(),
            'estimated_total' => round((float) $rows->sum(fn ($r) => (float) $r->estimated_cost), 2),
        ];
    }

    /** On-site vs remote and total effort, from the tasks themselves. */
    private function resourcing(Project $project, $taskIds): array
    {
        $tasks = $project->tasks()->with('logistics')->get();

        $onSite = $tasks->filter(fn ($t) => $t->logistics && ($t->logistics->needs_travel || $t->logistics->needs_client_access))->count();

        return [
            'tasks'        => $tasks->count(),
            'on_site'      => $onSite,
            'remote'       => $tasks->count() - $onSite,
            'effort_days'  => round((float) $tasks->sum(fn ($t) => (float) $t->effort_estimate_days), 1),
        ];
    }
}
