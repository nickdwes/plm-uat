<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectTravelRequest;
use App\Services\Project\TravelCycleBuilder;
use App\Support\Account\ReferenceSequence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The travel-request register on a project — approvable requests generated from
 * the travel schedule's cycles.
 */
class ProjectTravelRequestController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($this->payload($project));
    }

    /** Generate a request for each travel cycle not already requested. */
    public function generate(Request $request, Project $project, TravelCycleBuilder $builder): JsonResponse
    {
        $this->authorize('update', $project);

        $existing = $project->travelRequests()->pluck('cycle_key')->filter()->all();

        $created = 0;
        foreach ($builder->build($project) as $cycle) {
            if (in_array($cycle['key'], $existing, true)) {
                continue;
            }
            $count = count($cycle['tasks']);
            $req = $project->travelRequests()->create([
                'organization_id' => $project->organization_id,
                'reference'       => ReferenceSequence::next('TR', 'project_travel_requests', $project->organization_id),
                'cycle_key'       => $cycle['key'],
                'purpose'         => "Travel to {$cycle['location']} — {$count} task" . ($count === 1 ? '' : 's'),
                'location'        => $cycle['location'],
                'start_date'      => $cycle['start'],
                'end_date'        => $cycle['end'],
                'travel_days'     => $cycle['travel_days'],
                'mode'            => $cycle['modes'][0] ?? null,
                'accommodation'   => $cycle['accommodation'],
                'tasks'           => $cycle['tasks'],
                'personnel'       => $cycle['team'],
                'created_by'      => $request->user()->id,
            ]);
            $existing[] = $cycle['key'];
            $created++;

            // Link each covered task's travel prerequisite to this request — the
            // "real record" a travel requirement raises is its cycle's request.
            $taskIds = collect($cycle['tasks'])->pluck('id')->all();
            \App\Models\Task\TaskPrerequisite::whereIn('task_id', $taskIds)
                ->where('type', 'travel')
                ->whereNull('linked_id')
                ->update([
                    'linked_type' => \App\Models\Project\ProjectTravelRequest::class,
                    'linked_id'   => $req->id,
                    'status'      => \App\Models\Task\TaskPrerequisite::STATUS_IN_PROGRESS,
                    'detail'      => "Covered by travel request {$req->reference}.",
                ]);
        }

        return response()->json($this->payload($project) + ['created' => $created]);
    }

    public function update(Request $request, Project $project, ProjectTravelRequest $travelRequest): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($travelRequest->project_id === $project->id, 404);

        $travelRequest->update($request->validate([
            'purpose'        => ['sometimes', 'string', 'max:255'],
            'location'       => ['nullable', 'string', 'max:160'],
            'start_date'     => ['nullable', 'date'],
            'end_date'       => ['nullable', 'date', 'after_or_equal:start_date'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'notes'          => ['nullable', 'string', 'max:2000'],
        ]));

        return response()->json($this->payload($project));
    }

    public function decide(Request $request, Project $project, ProjectTravelRequest $travelRequest): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($travelRequest->project_id === $project->id, 404);

        $data = $request->validate([
            'approval_status' => ['required', Rule::in(ProjectTravelRequest::APPROVAL_STATUSES)],
        ]);
        $decided = $data['approval_status'] !== 'pending';
        $travelRequest->update($data + [
            'decided_by' => $decided ? $request->user()->id : null,
            'decided_at' => $decided ? now() : null,
        ]);

        return response()->json($this->payload($project));
    }

    public function destroy(Project $project, ProjectTravelRequest $travelRequest): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($travelRequest->project_id === $project->id, 404);

        $travelRequest->delete();

        return response()->json($this->payload($project));
    }

    /**
     * Raise this cycle into the corporate travel module — one per-traveller
     * request per assignee — so it enters the real approval/booking lifecycle
     * and is visible in the Travel module, filtered by project.
     */
    public function raiseInModule(Project $project, ProjectTravelRequest $travelRequest, \App\Services\Project\ProjectTravelBridge $bridge): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($travelRequest->project_id === $project->id, 404);

        $result = $bridge->raise($travelRequest, request()->user()->id);
        abort_if($result['no_travellers'], 422, 'This cycle has no task assignees to raise travel for — assign engineers to the travelling tasks first.');

        return response()->json($this->payload($project) + ['raised' => $result]);
    }

    private function payload(Project $project): array
    {
        $rows = $project->travelRequests()->latest('id')->get();

        // How many corporate-module requests each cycle has already spawned.
        $moduleCounts = \App\Models\Travel\TravelRequest::whereIn('source_project_travel_request_id', $rows->pluck('id'))
            ->selectRaw('source_project_travel_request_id, count(*) as c')
            ->groupBy('source_project_travel_request_id')->pluck('c', 'source_project_travel_request_id');

        return [
            'data' => $rows->map(fn (ProjectTravelRequest $r) => [
                'id'              => $r->id,
                'reference'       => $r->reference,
                'purpose'         => $r->purpose,
                'location'        => $r->location,
                'start_date'      => $r->start_date?->toDateString(),
                'end_date'        => $r->end_date?->toDateString(),
                'travel_days'     => $r->travel_days,
                'mode'            => $r->mode,
                'accommodation'   => $r->accommodation,
                'estimated_cost'  => $r->estimated_cost,
                'currency'        => $r->currency,
                'tasks'           => $r->tasks ?? [],
                'personnel'       => $r->personnel ?? [],
                'approval_status' => $r->approval_status,
                'notes'           => $r->notes,
                'module_requests' => (int) ($moduleCounts[$r->id] ?? 0),
            ])->values(),
            'summary' => [
                'count'            => $rows->count(),
                'pending_approval' => $rows->where('approval_status', 'pending')->count(),
                'estimated_total'  => (float) $rows->sum(fn ($r) => (float) $r->estimated_cost),
            ],
        ];
    }
}
