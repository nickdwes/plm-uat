<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectPerson;
use App\Models\Project\ProjectRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The project's team — managers, leads, and members assigned to named roles.
 */
class ProjectTeamController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['data' => $this->payload($project)]);
    }

    // ── Role buckets ─────────────────────────────────────────────────────────

    public function storeRole(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate(['name' => ['required', 'string', 'max:160']]);

        $project->roles()->create([
            'organization_id' => $project->organization_id,
            'name'     => $data['name'],
            'position' => ($project->roles()->max('position') ?? -1) + 1,
        ]);

        return response()->json(['data' => $this->payload($project)], 201);
    }

    public function destroyRole(Project $project, ProjectRole $role): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($role->project_id === $project->id, 404);

        $role->delete();

        return response()->json(['data' => $this->payload($project)]);
    }

    public function generateDefaultRoles(Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $existing = $project->roles()->pluck('name')->map(fn ($n) => strtolower($n))->all();
        $position = ($project->roles()->max('position') ?? -1) + 1;

        foreach (ProjectRole::DEFAULTS as $name) {
            if (in_array(strtolower($name), $existing, true)) {
                continue;
            }
            $project->roles()->create([
                'organization_id' => $project->organization_id,
                'name' => $name, 'position' => $position++,
            ]);
        }

        return response()->json(['data' => $this->payload($project)]);
    }

    /** Seed the ten standing delivery teams, each with its description. */
    public function generateDefaultTeams(Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $position = ($project->roles()->max('position') ?? -1) + 1;

        foreach (ProjectRole::TEAM_CATALOG as $key => [$name, $description]) {
            $project->roles()->firstOrCreate(
                ['team_key' => $key],
                [
                    'organization_id' => $project->organization_id,
                    'name' => $name, 'description' => $description, 'position' => $position++,
                ],
            );
        }

        return response()->json(['data' => $this->payload($project)]);
    }

    /** Edit a team/role's name or description. */
    public function updateRole(Request $request, Project $project, ProjectRole $role): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($role->project_id === $project->id, 404);

        $role->update($request->validate([
            'name'        => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]));

        return response()->json(['data' => $this->payload($project)]);
    }

    // ── People assignments ───────────────────────────────────────────────────

    public function storePerson(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'employee_id'     => ['required', 'integer', Rule::exists('employees', 'id')->where('primary_organization_id', $project->organization_id)],
            'role_kind'       => ['required', Rule::in(ProjectPerson::KINDS)],
            'project_role_id' => ['nullable', 'integer', 'exists:project_roles,id'],
            'is_lead'         => ['sometimes', 'boolean'],
            'capacity_percent'=> ['nullable', 'integer', 'min:0', 'max:100'],
            'assigned_from'   => ['nullable', 'date'],
            'assigned_to'     => ['nullable', 'date', 'after_or_equal:assigned_from'],
        ]);

        $roleId = $data['role_kind'] === ProjectPerson::KIND_MEMBER ? ($data['project_role_id'] ?? null) : null;

        // A person on a team is one row; re-adding updates their assignment.
        $person = $project->people()->firstOrNew([
            'employee_id'     => $data['employee_id'],
            'role_kind'       => $data['role_kind'],
            'project_role_id' => $roleId,
        ]);
        $person->fill([
            'organization_id'  => $project->organization_id,
            'is_lead'          => $data['is_lead'] ?? $person->is_lead ?? false,
            'capacity_percent' => $data['capacity_percent'] ?? $person->capacity_percent,
            'assigned_from'    => $data['assigned_from'] ?? $person->assigned_from,
            'assigned_to'      => $data['assigned_to'] ?? $person->assigned_to,
        ])->save();

        return response()->json([
            'data'    => $this->payload($project),
            'warning' => $this->allocationWarning($project, $person),
        ], 201);
    }

    public function destroyPerson(Project $project, ProjectPerson $person): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($person->project_id === $project->id, 404);

        $person->delete();

        return response()->json(['data' => $this->payload($project)]);
    }

    /** Edit a team member's lead flag, capacity and assignment window. */
    public function updatePerson(Request $request, Project $project, ProjectPerson $person): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($person->project_id === $project->id, 404);

        $person->update($request->validate([
            'is_lead'          => ['sometimes', 'boolean'],
            'capacity_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'assigned_from'    => ['nullable', 'date'],
            'assigned_to'      => ['nullable', 'date', 'after_or_equal:assigned_from'],
        ]));

        return response()->json(['data' => $this->payload($project)]);
    }

    /**
     * Resource-availability check: is this person now committed past 100% across
     * live projects? Advisory — the assignment is made, the over-allocation is
     * surfaced so it can be resolved.
     */
    private function allocationWarning(Project $project, ProjectPerson $person): ?string
    {
        if ($person->role_kind !== ProjectPerson::KIND_MEMBER || ! $person->capacity_percent) {
            return null;
        }

        $total = app(\App\Services\Project\ResourceAvailability::class)->totalAllocation(
            $person->employee_id, $project->organization_id,
            $person->assigned_from?->toDateString(), $person->assigned_to?->toDateString(),
        );

        if ($total <= 100) {
            return null;
        }

        $person->loadMissing('employee:id,first_name,last_name');
        $name = $person->employee ? trim($person->employee->first_name . ' ' . $person->employee->last_name) : 'This person';

        return "{$name} is now allocated {$total}% across live projects — over 100%. Review their commitments.";
    }

    private function payload(Project $project): array
    {
        $people = $project->people()->with('employee:id,first_name,last_name,work_email')->get();

        $person = fn (ProjectPerson $p) => [
            'id'          => $p->id,
            'employee_id' => $p->employee_id,
            'name'        => $p->employee ? trim($p->employee->first_name . ' ' . $p->employee->last_name) : '—',
            'email'       => $p->employee?->work_email,
            'role_kind'   => $p->role_kind,
            'project_role_id' => $p->project_role_id,
            'is_lead'          => (bool) $p->is_lead,
            'capacity_percent' => $p->capacity_percent,
            'assigned_from'    => $p->assigned_from?->toDateString(),
            'assigned_to'      => $p->assigned_to?->toDateString(),
        ];

        return [
            'managers' => $people->where('role_kind', ProjectPerson::KIND_MANAGER)->map($person)->values(),
            'leads'    => $people->where('role_kind', ProjectPerson::KIND_LEAD)->map($person)->values(),

            // Governance slots — sponsor, owner, project manager, engineer.
            'governance' => collect(ProjectPerson::GOVERNANCE)->mapWithKeys(fn (string $kind) => [
                $kind => $people->where('role_kind', $kind)->map($person)->values(),
            ]),

            'roles'    => $project->roles()->orderBy('position')->get()->map(fn (ProjectRole $r) => [
                'id'   => $r->id,
                'name' => $r->name,
                'team_key'    => $r->team_key,
                'description' => $r->description,
                'members' => $people->where('role_kind', ProjectPerson::KIND_MEMBER)
                    ->where('project_role_id', $r->id)->map($person)->values(),
            ])->values(),
        ];
    }
}
