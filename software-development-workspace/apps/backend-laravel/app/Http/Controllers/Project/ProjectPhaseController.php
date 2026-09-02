<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectPhase;
use App\Services\Project\ProjectPhaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProjectPhaseController extends Controller
{
    public function __construct(private readonly ProjectPhaseService $phases) {}

    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['data' => $this->phases->forProject($project)]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'name'      => ['required', 'string', 'max:160'],
            // A sub-phase hangs off one of THIS project's top-level phases —
            // scoped to the project, and only one level deep.
            'parent_id' => ['nullable', 'integer',
                Rule::exists('project_phases', 'id')->where('project_id', $project->id)->whereNull('parent_id')],
            'due_on'    => ['nullable', 'date'],
        ]);

        $project->allPhases()->create([
            'organization_id' => $project->organization_id,
            'name'      => $data['name'],
            'slug'      => Str::slug($data['name']) . '-' . Str::random(4),
            'parent_id' => $data['parent_id'] ?? null,
            'due_on'    => $data['due_on'] ?? null,
            'position'  => ($project->allPhases()->max('position') ?? -1) + 1,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->phases->forProject($project)], 201);
    }

    public function update(Request $request, Project $project, ProjectPhase $phase): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($phase->project_id === $project->id, 404);

        $phase->update($request->validate([
            'name'   => ['sometimes', 'string', 'max:160'],
            'due_on' => ['nullable', 'date'],
        ]));

        return response()->json(['data' => $this->phases->forProject($project)]);
    }

    public function destroy(Project $project, ProjectPhase $phase): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($phase->project_id === $project->id, 404);

        $phase->delete();

        return response()->json(['data' => $this->phases->forProject($project)]);
    }

    /** Seed the nine-stage default lifecycle, skipping any that already exist. */
    public function generateDefaults(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $existing = $project->allPhases()->pluck('name')->map(fn ($n) => strtolower($n))->all();
        $position = ($project->allPhases()->max('position') ?? -1) + 1;

        foreach (ProjectPhase::DEFAULTS as $name) {
            if (in_array(strtolower($name), $existing, true)) {
                continue;
            }
            $project->allPhases()->create([
                'organization_id' => $project->organization_id,
                'name' => $name, 'slug' => Str::slug($name) . '-' . Str::random(4),
                'position' => $position++, 'created_by' => $request->user()->id,
            ]);
        }

        return response()->json(['data' => $this->phases->forProject($project)]);
    }
}
