<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectProfessionalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Professional-services resources on a project — a list of advisory people
 * (consultants, SMEs, trainers…), added and listed like the other roles.
 */
class ProjectProfessionalServiceController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($this->payload($project));
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $project->professionalServices()->create($this->rules($request, $project) + [
            'organization_id' => $project->organization_id,
            'position'        => (int) $project->professionalServices()->max('position') + 1,
            'created_by'      => $request->user()->id,
        ]);

        return response()->json($this->payload($project), 201);
    }

    public function update(Request $request, Project $project, ProjectProfessionalService $professionalService): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($professionalService->project_id === $project->id, 404);

        $professionalService->update($this->rules($request, $project));

        return response()->json($this->payload($project));
    }

    public function destroy(Project $project, ProjectProfessionalService $professionalService): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($professionalService->project_id === $project->id, 404);

        $professionalService->delete();

        return response()->json($this->payload($project));
    }

    private function rules(Request $request, Project $project): array
    {
        return $request->validate([
            'role'         => ['required', Rule::in(array_keys(ProjectProfessionalService::CATALOG))],
            'name'         => ['required', 'string', 'max:160'],
            'assignee_employee_id' => ['nullable', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $project->organization_id)],
            'organization' => ['nullable', 'string', 'max:160'],
            'description'  => ['nullable', 'string', 'max:2000'],
            'contact_info' => ['nullable', 'string', 'max:255'],
            'start_date'   => ['nullable', 'date'],
            'end_date'     => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
    }

    private function payload(Project $project): array
    {
        return [
            'data' => $project->professionalServices()->orderBy('position')->get()->map(fn ($r) => [
                'id' => $r->id, 'role' => $r->role, 'role_label' => ProjectProfessionalService::CATALOG[$r->role] ?? $r->role,
                'name' => $r->name, 'organization' => $r->organization, 'description' => $r->description,
                'contact_info' => $r->contact_info,
                'start_date' => $r->start_date?->toDateString(), 'end_date' => $r->end_date?->toDateString(),
            ])->values(),
            'catalog' => collect(ProjectProfessionalService::CATALOG)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ];
    }
}
