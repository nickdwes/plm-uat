<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Account\AccountContact;
use App\Models\Project\Project;
use App\Models\Project\ProjectCustomerRole;
use App\Models\Project\ProjectGovernanceRole;
use App\Models\Project\ProjectPerson;
use App\Models\Project\ProjectDependency;
use App\Models\Project\ProjectProfessionalService;
use App\Models\Project\ProjectRisk;
use App\Models\Project\ProjectRole;
use App\Models\Project\ProjectSubcontractor;
use Illuminate\Http\JsonResponse;

/**
 * The project's governance reports, generated from the structures already
 * captured: the governance committee, delivery teams, customer team and
 * stakeholder register. One endpoint, six views.
 */
class ProjectReportsController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $gov = $this->governance($project);

        return response()->json([
            'raci'               => $this->raci($project, $gov),
            'org_chart'          => $this->orgChart($project, $gov),
            'governance'         => $gov,
            'stakeholders'       => $this->stakeholders($project),
            'customer_directory' => $this->customerDirectory($project),
            'escalation'         => $this->escalation($gov),
            'customer_escalation'=> $this->customerEscalation($project),
            'professional_services' => $this->professionalServices($project),
            'subcontractors'        => $this->subcontractors($project),
            'risk_dependency'       => $this->riskDependency($project),
        ]);
    }

    /**
     * A combined risk & dependency report: the risk register ranked by heat
     * (likelihood × impact), the dependency register with what each waits on,
     * and a summary that surfaces the exposures worth a conversation — high
     * risks still open, and dependencies past their due date.
     */
    private function riskDependency(Project $project): array
    {
        $name = fn ($e) => $e ? trim($e->first_name . ' ' . $e->last_name) : null;

        $risks = $project->risks()
            ->with(['owner:id,first_name,last_name', 'task:id,title', 'phase:id,name'])
            ->get()
            ->map(fn (ProjectRisk $r) => [
                'id'          => $r->id,
                'title'       => $r->title,
                'likelihood'  => $r->likelihood,
                'impact'      => $r->impact,
                'score'       => $r->score(),
                'level'       => $this->heat($r->score()),
                'status'      => $r->status,
                'owner'       => $name($r->owner),
                'mitigation'  => $r->mitigation,
                'against'     => $r->task?->title ?? $r->phase?->name,
            ])
            ->sortByDesc('score')->values();

        $today = now()->startOfDay();
        $deps = $project->dependencies()
            ->with(['owner:id,first_name,last_name', 'task:id,title', 'dependsOnTask:id,title,status'])
            ->get()
            ->map(function (ProjectDependency $d) use ($name, $today) {
                $resolved = $d->status === ProjectDependency::STATUS_RESOLVED;

                return [
                    'id'         => $d->id,
                    'title'      => $d->title,
                    'kind'       => $d->kind,
                    'status'     => $d->status,
                    'owner'      => $name($d->owner),
                    'due_on'     => $d->due_on?->toDateString(),
                    'overdue'    => (bool) ($d->due_on && ! $resolved && $d->due_on->lessThan($today)),
                    'against'    => $d->task?->title,
                    'waits_on'   => $d->dependsOnTask?->title,
                ];
            })
            ->sortBy([['overdue', 'desc'], ['status', 'asc']])->values();

        $openRisks = $risks->whereNotIn('status', [ProjectRisk::STATUS_CLOSED]);

        return [
            'risks'        => $risks,
            'dependencies' => $deps,
            'summary'      => [
                'risks_total'      => $risks->count(),
                'risks_open'       => $openRisks->count(),
                'risks_high'       => $openRisks->where('level', 'high')->count(),
                'top_risk'         => $openRisks->first()['title'] ?? null,
                'deps_total'       => $deps->count(),
                'deps_unresolved'  => $deps->where('status', '!=', ProjectDependency::STATUS_RESOLVED)->count(),
                'deps_overdue'     => $deps->where('overdue', true)->count(),
            ],
        ];
    }

    /** Heat band from the 1..9 risk score. */
    private function heat(int $score): string
    {
        return $score >= 6 ? 'high' : ($score >= 3 ? 'medium' : 'low');
    }

    /** The governance committee, as rows (reused across reports). */
    private function governance(Project $project): array
    {
        $rows = $project->governanceRoles()->with(['assignee:id,first_name,last_name', 'backup:id,first_name,last_name'])
            ->get()->keyBy('role_key');

        $name = fn ($e) => $e ? trim($e->first_name . ' ' . $e->last_name) : null;

        $out = [];
        foreach (ProjectGovernanceRole::CATALOG as $key => $label) {
            $r = $rows->get($key);
            $out[] = [
                'role'                 => $label,
                'role_key'             => $key,
                'is_mandatory'         => in_array($key, ProjectGovernanceRole::MANDATORY, true),
                'holder'               => $name($r?->assignee),
                'backup'               => $name($r?->backup),
                'responsibility'       => $r?->responsibility,
                'escalation_authority' => $r?->escalation_authority,
                'approval_authority'   => $r?->approval_authority,
            ];
        }

        return $out;
    }

    /** Responsibility assignment (RACI-style) from committee + team leads. */
    private function raci(Project $project, array $gov): array
    {
        $rows = [];
        foreach ($gov as $g) {
            if (! $g['holder'] && ! $g['responsibility']) {
                continue;
            }
            $rows[] = [
                'role'           => $g['role'],
                'person'         => $g['holder'],
                'responsible_for'=> $g['responsibility'],
                'accountable'    => $g['approval_authority'],   // A — what they own the sign-off for
                'consulted'      => $g['backup'],               // C — the delegate consulted
                'informed'       => $g['escalation_authority'], // I — who is kept informed / escalated to
            ];
        }

        // Delivery-team leads carry the R for their team.
        foreach ($this->teams($project) as $team) {
            if ($team['lead']) {
                $rows[] = [
                    'role'            => $team['name'] . ' — Lead',
                    'person'          => $team['lead'],
                    'responsible_for' => $team['description'],
                    'accountable'     => null, 'consulted' => null, 'informed' => null,
                ];
            }
        }

        return $rows;
    }

    /** Leadership tiers + delivery teams — an org chart the UI can lay out. */
    private function orgChart(Project $project, array $gov): array
    {
        $holder = function (string $key) use ($gov) {
            foreach ($gov as $g) {
                if ($g['role_key'] === $key) {
                    return ['role' => $g['role'], 'name' => $g['holder']];
                }
            }
            return null;
        };

        return [
            'leadership' => array_values(array_filter([
                $holder('executive_sponsor'), $holder('project_owner'),
                $holder('project_director'), $holder('project_manager'),
            ])),
            'teams' => $this->teams($project),
        ];
    }

    /** Delivery teams with lead + members. */
    private function teams(Project $project): array
    {
        $people = $project->people()->with('employee:id,first_name,last_name')->get();
        $name = fn (ProjectPerson $p) => $p->employee ? trim($p->employee->first_name . ' ' . $p->employee->last_name) : '—';

        return $project->roles()->orderBy('position')->get()->map(function (ProjectRole $r) use ($people, $name) {
            $members = $people->where('role_kind', ProjectPerson::KIND_MEMBER)->where('project_role_id', $r->id);
            $lead = $members->firstWhere('is_lead', true);

            return [
                'name'        => $r->name,
                'description' => $r->description,
                'lead'        => $lead ? $name($lead) : null,
                'members'     => $members->map(fn ($m) => [
                    'name' => $name($m), 'is_lead' => (bool) $m->is_lead,
                    'capacity_percent' => $m->capacity_percent,
                ])->values(),
            ];
        })->values()->all();
    }

    private function stakeholders(Project $project): array
    {
        return $project->stakeholders()->orderBy('position')->get()->map(fn ($s) => [
            'name' => $s->name, 'title' => $s->title, 'email' => $s->email, 'phone' => $s->phone,
            'persona' => $s->persona, 'influence' => $s->influence, 'interest' => $s->interest,
            'responsibility' => $s->responsibility,
        ])->values()->all();
    }

    private function customerDirectory(Project $project): array
    {
        $project->loadMissing('account');

        $roleByContact = $project->customerRoles()->get()
            ->filter(fn ($r) => $r->account_contact_id)
            ->mapWithKeys(fn ($r) => [$r->account_contact_id => ProjectCustomerRole::CATALOG[$r->role_key] ?? $r->role_key]);

        $contacts = $project->account_id
            ? AccountContact::where('account_id', $project->account_id)->where('is_active', true)
                ->orderBy('first_name')->get()->map(fn ($c) => [
                    'name'     => $c->full_name,
                    'category' => $c->category,
                    'email'    => $c->email,
                    'phone'    => $c->phone,
                    'project_role' => $roleByContact->get($c->id),
                ])->values()->all()
            : [];

        return [
            'organization' => $project->account?->name,
            'contacts'     => $contacts,
        ];
    }

    /**
     * The customer escalation matrix — the client's assigned roles from most
     * senior down, each escalating to the nearest more-senior assigned role.
     */
    private function customerEscalation(Project $project): array
    {
        // Seniority, highest first.
        $order = ['customer_sponsor', 'customer_owner', 'customer_pm', 'customer_tech_lead', 'customer_finance', 'customer_ops'];

        $rows = $project->customerRoles()->with('contact')->get()->keyBy('role_key');

        $out = [];
        $seniorAssigned = null; // the nearest more-senior role above the current one
        foreach ($order as $key) {
            $r = $rows->get($key);
            $c = $r?->contact;
            if ($c) {
                $out[] = [
                    'role'         => ProjectCustomerRole::CATALOG[$key] ?? $key,
                    'contact'      => $c->full_name,
                    'email'        => $c->email,
                    'escalates_to' => $seniorAssigned,
                ];
                $seniorAssigned = (ProjectCustomerRole::CATALOG[$key] ?? $key) . ' (' . $c->full_name . ')';
            }
        }

        return $out;
    }

    private function professionalServices(Project $project): array
    {
        return $project->professionalServices()->orderBy('position')->get()->map(fn ($r) => [
            'role'         => ProjectProfessionalService::CATALOG[$r->role] ?? $r->role,
            'name'         => $r->name,
            'organization' => $r->organization,
            'contact_info' => $r->contact_info,
            'period'       => $this->period($r->start_date, $r->end_date),
        ])->values()->all();
    }

    private function subcontractors(Project $project): array
    {
        return $project->subcontractors()->orderBy('position')->get()->map(fn ($r) => [
            'role'               => ProjectSubcontractor::CATALOG[$r->role] ?? $r->role,
            'company'            => $r->company,
            'contact_person'     => $r->contact_person,
            'scope_of_work'      => $r->scope_of_work,
            'contract_reference' => $r->contract_reference,
            'approval_status'    => $r->approval_status,
            'onboarding_status'  => $r->onboarding_status,
        ])->values()->all();
    }

    private function period($from, $to): ?string
    {
        if (! $from && ! $to) {
            return null;
        }
        return trim(($from?->format('d M Y') ?? '…') . ' → ' . ($to?->format('d M Y') ?? '…'));
    }

    /** Who escalates to whom, in seniority order. */
    private function escalation(array $gov): array
    {
        $rows = [];
        $level = 1;
        foreach ($gov as $g) {
            if (! $g['holder'] && ! $g['escalation_authority']) {
                continue;
            }
            $rows[] = [
                'level'        => $level++,
                'role'         => $g['role'],
                'holder'       => $g['holder'],
                'escalates_to' => $g['escalation_authority'],
            ];
        }

        return $rows;
    }
}
