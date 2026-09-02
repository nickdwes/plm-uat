<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\PmTaskDefinition;
use App\Models\Project\ProjectPhase;
use App\Models\Task\Task;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The organisation's standard PM activity checklist — the list "Generate PM
 * activities" draws from. An org-level definition, so it is gated on the
 * projects permissions rather than any one project's policy: seeing it needs
 * PROJECTS_VIEW, changing it PROJECTS_UPDATE.
 */
class PmTaskDefinitionController extends Controller
{
    public function index(): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::PROJECTS_VIEW), 403);

        $orgId = app('current.organization_id');
        PmTaskDefinition::ensureSeeded($orgId);

        $rows = PmTaskDefinition::orderBy('position')->orderBy('id')->get();

        return response()->json([
            'data'   => $rows->map(fn (PmTaskDefinition $d) => $this->row($d)),
            'phases' => ProjectPhase::DEFAULTS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::PROJECTS_UPDATE), 403);

        $orgId = app('current.organization_id');

        $data = $this->rules($request);
        $data['position'] = $data['position']
            ?? ((int) PmTaskDefinition::max('position') + 1);

        $definition = PmTaskDefinition::create($data + [
            'organization_id' => $orgId,
            'created_by'      => $request->user()->id,
        ]);

        return response()->json(['data' => $this->row($definition)], 201);
    }

    public function update(Request $request, PmTaskDefinition $pmDefinition): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::PROJECTS_UPDATE), 403);

        $pmDefinition->update($this->rules($request));

        return response()->json(['data' => $this->row($pmDefinition->fresh())]);
    }

    public function destroy(PmTaskDefinition $pmDefinition): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::PROJECTS_UPDATE), 403);

        $pmDefinition->delete();

        return response()->json(['deleted' => true]);
    }

    private function rules(Request $request): array
    {
        return $request->validate([
            'title'      => ['required', 'string', 'max:255'],
            // Free of a foreign key on purpose: a phase is matched to the
            // project's own phases by name at generation time, and a project
            // need not carry every phase.
            'phase'      => ['nullable', 'string', 'max:120'],
            'priority'   => ['sometimes', Rule::in(Task::PRIORITIES)],
            'weekly_okr' => ['sometimes', 'boolean'],
            'is_active'  => ['sometimes', 'boolean'],
            'position'   => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);
    }

    private function row(PmTaskDefinition $d): array
    {
        return [
            'id'         => $d->id,
            'phase'      => $d->phase,
            'title'      => $d->title,
            'priority'   => $d->priority,
            'weekly_okr' => $d->weekly_okr,
            'position'   => $d->position,
            'is_active'  => $d->is_active,
        ];
    }
}
