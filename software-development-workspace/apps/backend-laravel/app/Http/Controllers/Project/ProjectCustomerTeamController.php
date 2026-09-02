<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Account\AccountContact;
use App\Models\Project\Project;
use App\Models\Project\ProjectCustomerRole;
use App\Models\Project\ProjectStakeholder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The customer's side of the project — the client's named roles and the
 * stakeholder register. Customer people are the account's contacts; roles point
 * at those, stakeholders can link one or stand alone.
 */
class ProjectCustomerTeamController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($this->payload($project));
    }

    /** Set or clear one customer role. */
    public function upsertRole(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'role_key' => ['required', Rule::in(array_keys(ProjectCustomerRole::CATALOG))],
            'account_contact_id' => ['nullable', 'integer', $this->contactRule($project)],
            'responsibility'     => ['nullable', 'string', 'max:2000'],
        ]);

        $project->customerRoles()->updateOrCreate(
            ['role_key' => $data['role_key']],
            [
                'organization_id'    => $project->organization_id,
                'account_contact_id' => $data['account_contact_id'] ?? null,
                'responsibility'     => $data['responsibility'] ?? null,
                'created_by'         => $request->user()->id,
            ],
        );

        return response()->json($this->payload($project));
    }

    /** Seed the customer role list (all six, empty) so they show in the list. */
    public function generateTemplate(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        foreach (array_keys(ProjectCustomerRole::CATALOG) as $key) {
            $project->customerRoles()->firstOrCreate(
                ['role_key' => $key],
                ['organization_id' => $project->organization_id, 'created_by' => $request->user()->id],
            );
        }

        return response()->json($this->payload($project));
    }

    /** Clear a customer role entry. */
    public function destroyRole(Project $project, ProjectCustomerRole $customerRole): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($customerRole->project_id === $project->id, 404);

        $customerRole->delete();

        return response()->json($this->payload($project));
    }

    /** Add a contact to the project's client — so the roles have people to pick. */
    public function storeContact(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($project->account_id, 422, 'Link a client account to the project first.');

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name'  => ['nullable', 'string', 'max:120'],
            'title'      => ['nullable', 'string', 'max:60'],
            'designation'=> ['nullable', 'string', 'max:120'],
            'email'      => ['nullable', 'email', 'max:190'],
            'phone'      => ['nullable', 'string', 'max:60'],
            'category'   => ['nullable', Rule::in(AccountContact::CATEGORIES)],
        ]);

        AccountContact::create($data + [
            'organization_id' => $project->organization_id,
            'account_id'      => $project->account_id,
            'category'        => $data['category'] ?? 'other',
        ]);

        return response()->json($this->payload($project), 201);
    }

    public function storeStakeholder(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $project->stakeholders()->create($this->stakeholderRules($request, $project) + [
            'organization_id' => $project->organization_id,
            'position'        => (int) $project->stakeholders()->max('position') + 1,
            'created_by'      => $request->user()->id,
        ]);

        return response()->json($this->payload($project), 201);
    }

    public function updateStakeholder(Request $request, Project $project, ProjectStakeholder $stakeholder): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($stakeholder->project_id === $project->id, 404);

        $stakeholder->update($this->stakeholderRules($request, $project));

        return response()->json($this->payload($project));
    }

    public function destroyStakeholder(Project $project, ProjectStakeholder $stakeholder): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($stakeholder->project_id === $project->id, 404);

        $stakeholder->delete();

        return response()->json($this->payload($project));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function contactRule(Project $project)
    {
        return Rule::exists('account_contacts', 'id')
            ->where('organization_id', $project->organization_id)
            ->where('account_id', $project->account_id);
    }

    private function stakeholderRules(Request $request, Project $project): array
    {
        return $request->validate([
            'account_contact_id' => ['nullable', 'integer', $this->contactRule($project)],
            'name'           => ['required', 'string', 'max:160'],
            'title'          => ['nullable', 'string', 'max:120'],
            'email'          => ['nullable', 'email', 'max:190'],
            'phone'          => ['nullable', 'string', 'max:60'],
            'persona'        => ['nullable', 'string', 'max:120'],
            'influence'      => ['nullable', Rule::in(ProjectStakeholder::LEVELS)],
            'interest'       => ['nullable', Rule::in(ProjectStakeholder::LEVELS)],
            'responsibility' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function payload(Project $project): array
    {
        $project->loadMissing('account');

        $roleRows = $project->customerRoles()->with('contact')->get()->keyBy('role_key');

        $roles = [];
        foreach (ProjectCustomerRole::CATALOG as $key => $label) {
            $row = $roleRows->get($key);
            $c = $row?->contact;
            $roles[] = [
                'id'             => $row?->id,
                'role_key'       => $key,
                'label'          => $label,
                'present'        => $row !== null,
                'account_contact_id' => $row?->account_contact_id,
                'contact'        => $c ? ['id' => $c->id, 'name' => $c->full_name, 'email' => $c->email] : null,
                'responsibility' => $row?->responsibility,
            ];
        }

        $contacts = $project->account_id
            ? AccountContact::where('account_id', $project->account_id)->where('is_active', true)
                ->orderBy('first_name')->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->full_name, 'email' => $c->email, 'category' => $c->category])
                ->values()
            : [];

        return [
            'organization' => $project->account ? ['id' => $project->account->id, 'name' => $project->account->name] : null,
            'roles'        => $roles,
            'contacts'     => $contacts,
            'stakeholders' => $project->stakeholders()->orderBy('position')->get()->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'title' => $s->title, 'email' => $s->email, 'phone' => $s->phone,
                'persona' => $s->persona, 'influence' => $s->influence, 'interest' => $s->interest,
                'responsibility' => $s->responsibility, 'account_contact_id' => $s->account_contact_id,
            ])->values(),
        ];
    }
}
