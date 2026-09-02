<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectDeliveryMilestone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectDeliveryMilestoneController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'data' => $project->deliveryMilestones()
                ->with('owner:id,first_name,last_name')
                ->get()->map(fn (ProjectDeliveryMilestone $m) => $this->payload($m)),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $this->validated($request, creating: true);
        $data = $this->applyAchievement($data, null);

        $milestone = $project->deliveryMilestones()->create($data + [
            'organization_id' => $project->organization_id,
            'created_by'      => $request->user()->id,
            'position'        => (int) $project->deliveryMilestones()->max('position') + 1,
        ]);

        return response()->json(['data' => $this->payload($milestone->fresh('owner:id,first_name,last_name'))], 201);
    }

    public function update(Request $request, Project $project, ProjectDeliveryMilestone $milestone): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($milestone->project_id === $project->id, 404);

        $data = $this->validated($request, creating: false);
        $data = $this->applyAchievement($data, $milestone);

        $milestone->update($data);

        return response()->json(['data' => $this->payload($milestone->fresh('owner:id,first_name,last_name'))]);
    }

    public function destroy(Project $project, ProjectDeliveryMilestone $milestone): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($milestone->project_id === $project->id, 404);

        $milestone->delete();

        return response()->json(['message' => 'Milestone removed.']);
    }

    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title'       => [$req, 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:5000'],
            'target_date' => ['nullable', 'date'],
            'status'      => ['sometimes', Rule::in(ProjectDeliveryMilestone::STATUSES)],
            'owner_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);
    }

    /**
     * Reaching "achieved" stamps the date it was hit; stepping back off it
     * clears the stamp — so achieved_on always reflects the real state.
     */
    private function applyAchievement(array $data, ?ProjectDeliveryMilestone $existing): array
    {
        if (! array_key_exists('status', $data)) {
            return $data;
        }

        $wasAchieved = $existing?->status === ProjectDeliveryMilestone::STATUS_ACHIEVED;

        if ($data['status'] === ProjectDeliveryMilestone::STATUS_ACHIEVED && ! $wasAchieved) {
            $data['achieved_on'] = now()->toDateString();
        } elseif ($data['status'] !== ProjectDeliveryMilestone::STATUS_ACHIEVED) {
            $data['achieved_on'] = null;
        }

        return $data;
    }

    private function payload(ProjectDeliveryMilestone $m): array
    {
        return [
            'id'          => $m->id,
            'title'       => $m->title,
            'description' => $m->description,
            'target_date' => $m->target_date?->toDateString(),
            'status'      => $m->status,
            'achieved_on' => $m->achieved_on?->toDateString(),
            'is_achieved' => $m->is_achieved,
            'is_overdue'  => $m->is_overdue,
            'is_at_risk'  => $m->is_at_risk,
            'owner'       => $m->relationLoaded('owner') && $m->owner
                ? ['id' => $m->owner->id, 'name' => trim($m->owner->first_name . ' ' . $m->owner->last_name)] : null,
        ];
    }
}
