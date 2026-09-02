<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectGovernanceRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The project's governance committee — the fixed catalogue of roles, who holds
 * each, and their responsibility, authority and backup. Advisory: a vacancy in
 * a mandatory seat is warned about, not enforced.
 */
class ProjectGovernanceController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($this->payload($project));
    }

    /** Set or update one committee seat. */
    public function upsert(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $orgId = $project->organization_id;
        $data = $request->validate([
            'role_key'   => ['required', Rule::in(array_keys(ProjectGovernanceRole::CATALOG))],
            'assignee_employee_id' => ['nullable', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $orgId)],
            'backup_employee_id'   => ['nullable', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $orgId)],
            'responsibility'       => ['nullable', 'string', 'max:2000'],
            'escalation_authority' => ['nullable', 'string', 'max:500'],
            'approval_authority'   => ['nullable', 'string', 'max:500'],
            'department'           => ['nullable', 'string', 'max:160'],
            'contact_info'         => ['nullable', 'string', 'max:255'],
            'start_date'           => ['nullable', 'date'],
            'end_date'             => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $project->governanceRoles()->updateOrCreate(
            ['role_key' => $data['role_key']],
            [
                'organization_id'      => $orgId,
                'assignee_employee_id' => $data['assignee_employee_id'] ?? null,
                'backup_employee_id'   => $data['backup_employee_id'] ?? null,
                'responsibility'       => $data['responsibility'] ?? null,
                'escalation_authority' => $data['escalation_authority'] ?? null,
                'approval_authority'   => $data['approval_authority'] ?? null,
                'department'           => $data['department'] ?? null,
                'contact_info'         => $data['contact_info'] ?? null,
                'start_date'           => $data['start_date'] ?? null,
                'end_date'             => $data['end_date'] ?? null,
                'is_mandatory'         => in_array($data['role_key'], ProjectGovernanceRole::MANDATORY, true),
                'created_by'           => $request->user()->id,
            ],
        );

        return response()->json($this->payload($project));
    }

    /** Seed the default template — the mandatory seats, ready to fill. */
    public function generateTemplate(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        foreach (ProjectGovernanceRole::MANDATORY as $key) {
            $project->governanceRoles()->firstOrCreate(
                ['role_key' => $key],
                ['organization_id' => $project->organization_id, 'is_mandatory' => true, 'created_by' => $request->user()->id],
            );
        }

        return response()->json($this->payload($project));
    }

    /** Clear a seat entirely. */
    public function destroy(Project $project, ProjectGovernanceRole $governanceRole): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($governanceRole->project_id === $project->id, 404);

        $governanceRole->delete();

        return response()->json($this->payload($project));
    }

    private function payload(Project $project): array
    {
        $rows = $project->governanceRoles()->with(['assignee:id,first_name,last_name', 'backup:id,first_name,last_name'])
            ->get()->keyBy('role_key');

        $person = fn ($e) => $e ? ['id' => $e->id, 'name' => trim($e->first_name . ' ' . $e->last_name)] : null;

        $roles = [];
        foreach (ProjectGovernanceRole::CATALOG as $key => $label) {
            $row = $rows->get($key);
            $roles[] = [
                'id'                   => $row?->id,
                'role_key'             => $key,
                'label'                => $label,
                'is_mandatory'         => in_array($key, ProjectGovernanceRole::MANDATORY, true),
                'present'              => $row !== null,
                'assignee'             => $person($row?->assignee),
                'backup'               => $person($row?->backup),
                'responsibility'       => $row?->responsibility,
                'escalation_authority' => $row?->escalation_authority,
                'approval_authority'   => $row?->approval_authority,
                'department'           => $row?->department,
                'contact_info'         => $row?->contact_info,
                'start_date'           => $row?->start_date?->toDateString(),
                'end_date'             => $row?->end_date?->toDateString(),
                'filled'               => (bool) $row?->assignee_employee_id,
            ];
        }

        // The governance warning: mandatory seats with nobody in them.
        $vacantMandatory = collect($roles)
            ->filter(fn ($r) => $r['is_mandatory'] && ! $r['filled'])
            ->pluck('label')->values()->all();

        return [
            'data'             => $roles,
            'vacant_mandatory' => $vacantMandatory,
        ];
    }
}
