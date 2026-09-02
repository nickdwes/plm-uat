<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectVendorEngagement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The vendor-engagement register on a project — the demands raised by task
 * requirements for an external vendor, professional-services resource or
 * subcontractor, worked from identifying a supplier through to active.
 */
class ProjectVendorEngagementController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($this->payload($project));
    }

    public function update(Request $request, Project $project, ProjectVendorEngagement $engagement): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($engagement->project_id === $project->id, 404);

        $data = $request->validate([
            'vendor_partner_id' => ['nullable', 'integer', Rule::exists('vendor_partners', 'id')->where('organization_id', $project->organization_id)],
            'vendor_name'       => ['nullable', 'string', 'max:200'],
            'scope'             => ['nullable', 'string', 'max:2000'],
            'status'            => ['sometimes', Rule::in(ProjectVendorEngagement::STATUSES)],
            'notes'             => ['nullable', 'string', 'max:2000'],
        ]);

        $engagement->update($data);

        return response()->json($this->payload($project));
    }

    public function destroy(Project $project, ProjectVendorEngagement $engagement): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($engagement->project_id === $project->id, 404);

        $engagement->delete();

        return response()->json($this->payload($project));
    }

    private function payload(Project $project): array
    {
        $rows = $project->vendorEngagements()->with(['vendorPartner:id,name', 'task:id,title'])->get();

        return [
            'data' => $rows->map(fn (ProjectVendorEngagement $e) => [
                'id'              => $e->id,
                'reference'       => $e->reference,
                'engagement_type' => $e->engagement_type,
                'vendor'          => $e->vendorPartner?->name ?? $e->vendor_name,
                'vendor_partner_id' => $e->vendor_partner_id,
                'vendor_name'     => $e->vendor_name,
                'scope'           => $e->scope,
                'status'          => $e->status,
                'notes'           => $e->notes,
                'task'            => $e->task?->title,
                'task_id'         => $e->task_id,
            ])->values(),
            'summary' => [
                'count'  => $rows->count(),
                'open'   => $rows->whereNotIn('status', [ProjectVendorEngagement::STATUS_CLOSED])->count(),
                'active' => $rows->where('status', ProjectVendorEngagement::STATUS_ACTIVE)->count(),
            ],
            'options' => [
                'types'    => ProjectVendorEngagement::TYPES,
                'statuses' => ProjectVendorEngagement::STATUSES,
            ],
        ];
    }
}
