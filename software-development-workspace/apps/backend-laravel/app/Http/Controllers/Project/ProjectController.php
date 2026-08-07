<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Http\Resources\Project\ProjectResource;
use App\Models\Project\Project;
use App\Models\Project\ProjectTeamMember;
use App\Support\Account\ReferenceSequence;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The work a won deal starts.
 *
 * Deliberately short of a full project module — tasks, milestones, time and
 * billing are their own thing, and Tasks is already a separate entry in the
 * navigation. What is here is delivery: who is on it, when it is due, when it
 * actually started and finished, and the paperwork it was won against.
 */
class ProjectController extends Controller
{
    private const DETAIL = [
        'account', 'opportunity.estimates', 'projectLead', 'team.employee', 'vendorPartner',
    ];

    /** Someone holding only `view_own` sees what they lead or deliver. */
    private function limitToOwn($q, Request $request): void
    {
        if ($request->user()->hasPermission(Permission::PROJECTS_VIEW)) {
            return;
        }

        $employeeId = $request->user()->employeeId();

        $q->where(fn ($w) => $w->where('project_lead_id', $employeeId)
            ->orWhereHas('team', fn ($t) => $t->where('employee_id', $employeeId)));
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Project::class);

        $q = Project::query()->with(['account', 'opportunity', 'projectLead', 'team.employee']);

        $this->limitToOwn($q, $request);

        foreach (['account_id', 'opportunity_id'] as $key) {
            if ($id = $request->integer($key)) {
                $q->where($key, $id);
            }
        }

        match ($request->string('state')->toString()) {
            // The question people arrive with, and the one a status filter
            // cannot answer: what is late right now.
            'overdue'   => $q->whereNull('delivered_on')
                ->whereNotIn('status', ['delivered', 'cancelled'])
                ->whereDate('deadline', '<', now()->toDateString()),
            'running'   => $q->whereNotIn('status', ['delivered', 'cancelled']),
            'delivered' => $q->where('status', 'delivered'),
            default     => null,
        };

        if ($term = trim((string) $request->query('search', ''))) {
            $q->where('name', 'ilike', '%' . $term . '%');
        }

        return ProjectResource::collection(
            $q->latest('id')->paginate((int) $request->input('per_page', 25))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Project::class);

        $orgId = app('current.organization_id');

        $project = Project::create($this->rules($request, $orgId) + [
            'reference'       => ReferenceSequence::next('PR', 'projects', $orgId),
            'organization_id' => $orgId,
            'created_by'      => $request->user()->id,
        ]);

        return (new ProjectResource($project->load(self::DETAIL)))->response()->setStatusCode(201);
    }

    public function show(Project $project): ProjectResource
    {
        $this->authorize('view', $project);

        return new ProjectResource($project->load(self::DETAIL));
    }

    public function update(Request $request, Project $project): ProjectResource
    {
        $this->authorize('update', $project);

        $data = $this->rules($request, $project->organization_id);

        /*
         * Delivering a project stamps the date if nobody gave one, and clearing
         * the status back to running clears it. A delivered_on left behind on a
         * project that went back into flight is how "was it late" starts lying.
         */
        if (($data['status'] ?? null) === 'delivered' && empty($data['delivered_on'])) {
            $data['delivered_on'] = now()->toDateString();
        }

        if (isset($data['status']) && ! in_array($data['status'], ['delivered', 'cancelled'], true)) {
            $data['delivered_on'] = null;
        }

        $project->update($data);

        return new ProjectResource($project->fresh(self::DETAIL));
    }

    public function destroy(Project $project): JsonResponse
    {
        $this->authorize('delete', $project);
        $project->delete();

        return response()->json(['message' => 'Project removed.']);
    }

    // ── Team ──────────────────────────────────────────────────────────────

    public function storeTeamMember(Request $request, Project $project): ProjectResource
    {
        // Staffing, not editing: putting somebody on a project commits their
        // time, which is a different decision from correcting a deadline.
        $this->authorize('assign', $project);

        $data = $request->validate([
            'employee_id' => ['required', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $project->organization_id)],
            'role' => ['nullable', 'string', 'max:120'],
        ]);

        ProjectTeamMember::updateOrCreate(
            ['project_id' => $project->id, 'employee_id' => $data['employee_id']],
            ['organization_id' => $project->organization_id, 'role' => $data['role'] ?? null],
        );

        return new ProjectResource($project->fresh(self::DETAIL));
    }

    public function destroyTeamMember(Project $project, ProjectTeamMember $member): ProjectResource
    {
        $this->authorize('assign', $project);
        abort_unless($member->project_id === $project->id, 404);

        $member->delete();

        return new ProjectResource($project->fresh(self::DETAIL));
    }

    private function rules(Request $request, int $orgId): array
    {
        $scoped = fn (string $table) => Rule::exists($table, 'id')->where('organization_id', $orgId);

        return $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],

            'status'   => ['sometimes', Rule::in(Project::STATUSES)],
            'priority' => ['sometimes', Rule::in(Project::PRIORITIES)],

            'deadline'   => ['nullable', 'date'],
            'started_on' => ['nullable', 'date'],
            // A project cannot be delivered before it started; the pair is the
            // only thing "was it late" is computed from.
            'delivered_on' => ['nullable', 'date', 'after_or_equal:started_on'],

            'account_id'     => ['nullable', 'integer', $scoped('accounts')],
            'opportunity_id' => ['nullable', 'integer', $scoped('opportunities')],

            'project_lead_id' => ['nullable', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $orgId)],

            'contract_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],

            // The delivered split, recorded separately from what was planned.
            'vendor_partner_id' => ['nullable', 'integer',
                Rule::exists('vendor_partners', 'id')->where('organization_id', $orgId)],
            'delivery_days_own' => ['nullable', 'numeric', 'min:0', 'max:99999.9'],
            'delivery_days_oem' => ['nullable', 'numeric', 'min:0', 'max:99999.9'],
            'oem_ps_cost'       => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
        ]);
    }
}
