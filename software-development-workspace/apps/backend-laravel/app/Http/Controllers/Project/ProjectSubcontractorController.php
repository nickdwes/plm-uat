<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectSubcontractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Subcontractor firms on a project — company, scope, contract reference and
 * where approval and onboarding stand.
 */
class ProjectSubcontractorController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($this->payload($project));
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $project->subcontractors()->create($this->rules($request) + [
            'organization_id' => $project->organization_id,
            'position'        => (int) $project->subcontractors()->max('position') + 1,
            'created_by'      => $request->user()->id,
        ]);

        return response()->json($this->payload($project), 201);
    }

    public function update(Request $request, Project $project, ProjectSubcontractor $subcontractor): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($subcontractor->project_id === $project->id, 404);

        $subcontractor->update($this->rules($request));

        return response()->json($this->payload($project));
    }

    public function destroy(Project $project, ProjectSubcontractor $subcontractor): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($subcontractor->project_id === $project->id, 404);

        $subcontractor->delete();

        return response()->json($this->payload($project));
    }

    private function rules(Request $request): array
    {
        return $request->validate([
            'role'               => ['required', Rule::in(array_keys(ProjectSubcontractor::CATALOG))],
            'company'            => ['required', 'string', 'max:160'],
            'contact_person'     => ['nullable', 'string', 'max:160'],
            'contact_info'       => ['nullable', 'string', 'max:255'],
            'scope_of_work'      => ['nullable', 'string', 'max:2000'],
            'contract_reference' => ['nullable', 'string', 'max:160'],
            'approval_status'    => ['sometimes', Rule::in(ProjectSubcontractor::APPROVAL_STATUSES)],
            'onboarding_status'  => ['sometimes', Rule::in(ProjectSubcontractor::ONBOARDING_STATUSES)],
            'start_date'         => ['nullable', 'date'],
            'end_date'           => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
    }

    private function payload(Project $project): array
    {
        return [
            'data' => $project->subcontractors()->orderBy('position')->get()->map(fn ($r) => [
                'id' => $r->id, 'role' => $r->role, 'role_label' => ProjectSubcontractor::CATALOG[$r->role] ?? $r->role,
                'company' => $r->company, 'contact_person' => $r->contact_person, 'contact_info' => $r->contact_info,
                'scope_of_work' => $r->scope_of_work, 'contract_reference' => $r->contract_reference,
                'approval_status' => $r->approval_status, 'onboarding_status' => $r->onboarding_status,
                'start_date' => $r->start_date?->toDateString(), 'end_date' => $r->end_date?->toDateString(),
            ])->values(),
            'catalog' => collect(ProjectSubcontractor::CATALOG)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ];
    }
}
