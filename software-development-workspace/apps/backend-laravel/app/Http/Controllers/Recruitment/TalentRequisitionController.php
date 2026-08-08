<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Models\Recruitment\RecruitmentWorkflow;
use App\Models\Recruitment\RequisitionApprovalPolicy;
use App\Models\Recruitment\TalentRequisition;
use App\Models\Recruitment\VacancyTemplate;
use App\Services\Recruitment\RequisitionService;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Talent requisitions — asking for a role, and the decision on it.
 *
 * The list, its five counts and its tabs all read the same query, so a tab
 * showing eleven rows above a card saying ten is not a thing that can happen.
 */
class TalentRequisitionController extends Controller
{
    private const DETAIL = [
        'department', 'requester', 'approvals.approver', 'events.actor', 'vacancy',
        'template', 'workflow.stages',
    ];

    public function __construct(private RequisitionService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TalentRequisition::class);

        $q = $this->visible($request)->with(['department', 'requester'])->latest('id');

        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }

        if ($priority = $request->string('priority')->toString()) {
            $q->where('priority', $priority);
        }

        if ($departmentId = $request->integer('department_id')) {
            $q->where('department_id', $departmentId);
        }

        if ($term = trim((string) $request->query('search', ''))) {
            $q->where(fn ($w) => $w->where('job_title', 'ilike', "%{$term}%")
                ->orWhere('reference', 'ilike', "%{$term}%"));
        }

        $rows = $q->paginate((int) $request->input('per_page', 10));

        return response()->json([
            'data' => collect($rows->items())->map(fn (TalentRequisition $r) => $this->row($r)),
            'meta' => [
                'total' => $rows->total(), 'per_page' => $rows->perPage(),
                'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(),
            ],
        ]);
    }

    /**
     * The five cards across the top.
     *
     * Counted from the same scope the list uses, so a card can never total
     * requisitions its reader is not allowed to open.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TalentRequisition::class);

        $scope = fn () => $this->visible($request);

        return response()->json([
            'total'       => $scope()->count(),
            'open'        => $scope()->where('status', 'open')->count(),
            'in_approval' => $scope()->where('status', 'in_approval')->count(),
            'on_hold'     => $scope()->where('status', 'on_hold')->count(),
            // "Closed (This month)" on the card — a running total of everything
            // ever closed says nothing about how the year is going.
            'closed_this_month' => $scope()->where('status', 'closed')
                ->whereBetween('updated_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),

            /*
             * Not on the designs' cards, but it is what the person reading the
             * page can act on. Everything else is somebody else's queue.
             */
            'awaiting_me' => $this->awaitingMeCount($request),
        ]);
    }

    /** Requisitions with a step waiting on the person reading this. */
    public function inbox(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TalentRequisition::class);

        $me = $request->user()->employeeId();

        $rows = TalentRequisition::with(['department', 'requester', 'approvals'])
            ->where('status', 'in_approval')
            ->whereHas('approvals', fn ($a) => $a->awaiting()->where('employee_id', $me))
            ->latest('id')->get();

        return response()->json(['data' => $rows->map(fn (TalentRequisition $r) => $this->row($r))]);
    }

    public function show(Request $request, TalentRequisition $requisition): JsonResponse
    {
        $this->authorize('view', $requisition);

        return response()->json(['data' => $this->detail($requisition->load(self::DETAIL), $request)]);
    }

    /**
     * Raise one.
     *
     * Submitted straight into approval by default, because that is what the
     * designs' single "Submit Request" button means. Saving a draft is a
     * separate, explicit choice.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', TalentRequisition::class);

        $orgId = app('current.organization_id');
        $data  = $this->rules($request, $orgId);

        /*
         * Created and submitted together, or not at all.
         *
         * Submitting fails when the department has no approval chain, and
         * without this the row is already written — leaving a draft nobody
         * asked for, with a reference burnt out of the year's sequence.
         */
        $requisition = DB::transaction(function () use ($data, $orgId, $request) {
            $requisition = TalentRequisition::create($this->fromTemplate($data, $orgId) + [
                'organization_id' => $orgId,
                'reference'    => $this->service->nextReference($orgId),
                'status'       => 'draft',
                'requested_by' => $request->user()->employeeId(),
                'created_by'   => $request->user()->id,
            ]);

            if ($request->boolean('submit', true)) {
                $this->service->submit($requisition);
            }

            return $requisition;
        });

        return response()->json([
            'data' => $this->detail($requisition->fresh(self::DETAIL), $request),
        ], 201);
    }

    public function update(Request $request, TalentRequisition $requisition): JsonResponse
    {
        $this->authorize('update', $requisition);

        $requisition->update($this->rules($request, $requisition->organization_id));

        return response()->json(['data' => $this->detail($requisition->fresh(self::DETAIL), $request)]);
    }

    /** Send a draft — or a held or declined one — back for approval. */
    public function submit(Request $request, TalentRequisition $requisition): JsonResponse
    {
        $this->authorize('update', $requisition);

        abort_if(
            in_array($requisition->status, ['in_approval', 'approved', 'open', 'closed'], true),
            422,
            'That requisition has already been submitted.',
        );

        $this->service->submit($requisition);

        return response()->json(['data' => $this->detail($requisition->fresh(self::DETAIL), $request)]);
    }

    /**
     * Approve, hold or decline.
     *
     * Authorised by the chain rather than by a permission: whether you may
     * decide this requisition depends on whether it named you, which no role
     * can answer.
     */
    public function decide(Request $request, TalentRequisition $requisition): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'held', 'declined'])],
            'reason'   => ['nullable', 'string', 'max:2000'],
        ]);

        $actor = $request->user()->employee;
        abort_if($actor === null, 422, 'You have no employee record.');

        $this->service->decide($requisition->load('approvals'), $actor, $data['decision'], $data['reason'] ?? null);

        return response()->json(['data' => $this->detail($requisition->fresh(self::DETAIL), $request)]);
    }

    /** Put a held requisition back in front of the person who held it. */
    public function resume(Request $request, TalentRequisition $requisition): JsonResponse
    {
        $this->authorize('update', $requisition);

        $actor = $request->user()->employee;
        abort_if($actor === null, 422, 'You have no employee record.');

        $this->service->resume($requisition, $actor);

        return response()->json(['data' => $this->detail($requisition->fresh(self::DETAIL), $request)]);
    }

    /** Close one that was filled or withdrawn. Not the same as declining it. */
    public function close(Request $request, TalentRequisition $requisition): JsonResponse
    {
        $this->authorize('update', $requisition);

        abort_unless(
            in_array($requisition->status, ['approved', 'open', 'on_hold'], true),
            422,
            'Only an approved or open requisition can be closed.',
        );

        $requisition->update(['status' => 'closed']);

        $requisition->events()->create([
            'organization_id' => $requisition->organization_id,
            'status' => 'closed',
            'actor_employee_id' => $request->user()->employeeId(),
            'occurred_at' => now(),
        ]);

        return response()->json(['data' => $this->detail($requisition->fresh(self::DETAIL), $request)]);
    }

    /**
     * Fill the gaps from the chosen template.
     *
     * Only the gaps: anything typed on the form wins, because somebody who
     * changed the job title while the template was selected meant it. The
     * workflow is pinned here rather than followed live, so retemplating later
     * cannot move candidates already on a board.
     */
    private function fromTemplate(array $data, int $orgId): array
    {
        $templateId = $data['vacancy_template_id'] ?? null;

        if ($templateId === null) {
            // No template still needs a pipeline, or the opening has no board
            // to put candidates on. The organisation's default is the answer.
            $data['workflow_id'] = RecruitmentWorkflow::where('organization_id', $orgId)
                ->where('is_default', true)->value('id');

            return $data;
        }

        $template = VacancyTemplate::where('organization_id', $orgId)->find($templateId);

        if ($template === null) {
            return $data;
        }

        $data['workflow_id'] = $template->workflow_id
            ?? RecruitmentWorkflow::where('organization_id', $orgId)->where('is_default', true)->value('id');

        foreach ([
            'job_title'     => $template->job_title,
            'department_id' => $template->department_id,
            'positions'     => $template->default_positions,
        ] as $field => $value) {
            if (blank($data[$field] ?? null) && filled($value)) {
                $data[$field] = $value;
            }
        }

        return $data;
    }

    // ── Shapes ────────────────────────────────────────────────────────────

    private function rules(Request $request, int $orgId): array
    {
        return $request->validate([
            'job_title'     => ['required', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer',
                Rule::exists('departments', 'id')->where('organization_id', $orgId)],

            /*
             * The template carries the job details, the application form, the
             * screening rules and the pipeline. Optional — a role nobody has
             * templated is a normal thing to ask for.
             */
            'vacancy_template_id' => ['nullable', 'integer',
                Rule::exists('vacancy_templates', 'id')->where('organization_id', $orgId)],
            'positions' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'priority'  => ['sometimes', Rule::in(TalentRequisition::PRIORITIES)],
            'target_hire_date' => ['nullable', 'date'],
            'employment_type'  => ['nullable', Rule::in(TalentRequisition::EMPLOYMENT_TYPES)],
            // Free text: "1 Year". Only meaningful for fixed-term work, which
            // is why it is not a number of months on every row.
            'period' => ['nullable', 'string', 'max:64'],

            'job_summary'  => ['nullable', 'string', 'max:20000'],
            'key_responsibilities'   => ['nullable', 'string', 'max:20000'],
            'required_qualifications' => ['nullable', 'string', 'max:20000'],
            'skills'   => ['nullable', 'array', 'max:40'],
            'skills.*' => ['string', 'max:64'],
        ]);
    }

    /** Everything the reader may open — the same scope the counts use. */
    private function visible(Request $request)
    {
        $q = TalentRequisition::query();

        if ($request->user()->hasPermission(Permission::REQUISITIONS_VIEW)) {
            return $q;
        }

        $me = $request->user()->employeeId();

        return $q->where(fn ($w) => $w->where('requested_by', $me)
            ->orWhereHas('approvals', fn ($a) => $a->where('employee_id', $me)));
    }

    private function awaitingMeCount(Request $request): int
    {
        $me = $request->user()->employeeId();

        return $me === null ? 0 : TalentRequisition::where('status', 'in_approval')
            ->whereHas('approvals', fn ($a) => $a->awaiting()->where('employee_id', $me))
            ->count();
    }

    private function row(TalentRequisition $r): array
    {
        return [
            'id'        => $r->id,
            'reference' => $r->reference,
            'job_title' => $r->job_title,
            'department' => $r->relationLoaded('department') && $r->department
                ? ['id' => $r->department->id, 'name' => $r->department->name] : null,
            'positions' => $r->positions,
            'priority'  => $r->priority,
            'status'    => $r->status,
            'target_hire_date' => $r->target_hire_date?->toDateString(),
            'created_at' => $r->created_at?->toDateString(),
            'requester' => $r->relationLoaded('requester') && $r->requester
                ? [
                    'id' => $r->requester->id,
                    'name' => $r->requester->full_name,
                    'email' => $r->requester->work_email ?? $r->requester->personal_email,
                ]
                : null,
        ];
    }

    private function detail(TalentRequisition $r, Request $request): array
    {
        $me = $request->user()->employeeId();
        $current = $r->approvals->firstWhere('status', 'pending');

        return $this->row($r) + [
            'employment_type' => $r->employment_type,
            'period'          => $r->period,
            'job_summary'     => $r->job_summary,
            'key_responsibilities'   => $r->key_responsibilities,
            'required_qualifications' => $r->required_qualifications,
            'skills'          => $r->skills ?? [],
            'decision_reason' => $r->decision_reason,
            'is_approved'     => $r->is_approved,

            'vacancy' => $r->relationLoaded('vacancy') && $r->vacancy
                ? ['id' => $r->vacancy->id, 'name' => $r->vacancy->name, 'status' => $r->vacancy->status]
                : null,

            /*
             * The configuration this requisition inherited, shown so the
             * requester can see the whole journey before agreeing to it —
             * which application form candidates will fill in, and which board
             * they will move down.
             */
            'template' => $r->relationLoaded('template') && $r->template
                ? [
                    'id' => $r->template->id,
                    'name' => $r->template->name,
                    'form_fields' => count($r->template->application_form_schema ?? []),
                    'has_screening_rules' => filled($r->template->screening_rules),
                ]
                : null,

            'pipeline' => $r->relationLoaded('workflow') && $r->workflow
                ? [
                    'id' => $r->workflow->id,
                    'name' => $r->workflow->name,
                    'stages' => $r->workflow->relationLoaded('stages')
                        ? $r->workflow->stages->map(fn ($s) => [
                            'name' => $s->name, 'type' => $s->stage_type,
                        ])->values()
                        : [],
                ]
                : null,

            /*
             * The requester leads the list, as the designs show — the first
             * row is "Requested", not an approval. Reading it as a decision
             * would suggest people approve their own requisitions.
             */
            'approvals' => $r->approvals->map(fn ($a) => [
                'id'       => $a->id,
                'position' => $a->position,
                'label'    => $a->label,
                'status'   => $a->status,
                'reason'   => $a->reason,
                'actioned_at' => $a->actioned_at?->toIso8601String(),
                'approver' => $a->relationLoaded('approver') && $a->approver
                    ? [
                        'id' => $a->approver->id,
                        'name' => $a->approver->full_name,
                        'email' => $a->approver->work_email ?? $a->approver->personal_email,
                    ]
                    : null,
                // A step nobody could be resolved for. Visible rather than
                // silently skipped, or the chain looks followed when it is not.
                'unassigned' => $a->employee_id === null,
            ])->values(),

            'timeline' => $r->events->map(fn ($e) => [
                'status' => $e->status,
                'note'   => $e->note,
                'at'     => $e->occurred_at?->toIso8601String(),
                'actor'  => $e->relationLoaded('actor') && $e->actor ? $e->actor->full_name : null,
            ])->values(),

            // Answered by the server rather than the client comparing ids —
            // an Approve button the server will refuse is worse than none.
            'can_decide' => $current !== null && $me !== null && $current->employee_id === $me,
            'awaiting'   => $current ? ['label' => $current->label, 'position' => $current->position] : null,
        ];
    }

    /**
     * The templates worth offering, with what each one brings.
     *
     * Shown before the requisition is raised, so the requester can see they
     * are inheriting a form and a pipeline rather than discovering it later.
     */
    public function templates(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TalentRequisition::class);

        $rows = VacancyTemplate::with('workflow')
            ->where('is_active', true)
            ->orderBy('name')->get();

        return response()->json([
            'data' => $rows->map(fn (VacancyTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'job_title' => $t->job_title,
                'department_id' => $t->department_id,
                'default_positions' => $t->default_positions,
                // What comes with it, so the choice is an informed one.
                'form_fields' => count($t->application_form_schema ?? []),
                'has_screening_rules' => filled($t->screening_rules),
                'pipeline' => $t->relationLoaded('workflow') && $t->workflow
                    ? ['id' => $t->workflow->id, 'name' => $t->workflow->name] : null,
            ]),
        ]);
    }

    /** The chain a department would get, so the form can show it up front. */
    public function policyFor(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TalentRequisition::class);

        $policy = RequisitionApprovalPolicy::forDepartment(
            app('current.organization_id'),
            $request->integer('department_id') ?: null,
        );

        return response()->json([
            'data' => $policy === null ? null : [
                'name'  => $policy->name,
                'scope' => $policy->department_id ? 'department' : 'organisation',
                'steps' => $policy->steps->map(fn ($s) => [
                    'position' => $s->position, 'label' => $s->label, 'resolver' => $s->resolver,
                ])->values(),
            ],
        ]);
    }
}
