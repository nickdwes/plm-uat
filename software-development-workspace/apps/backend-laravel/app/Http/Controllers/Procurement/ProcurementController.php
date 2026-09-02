<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Project\Project;
use App\Support\Account\ReferenceSequence;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The procurement register — read and managed both on a project (its own items)
 * and org-wide as a module. Project surfaces authorize against the project;
 * the module surface sits under asset management (assets permissions).
 */
class ProcurementController extends Controller
{
    /** What a row needs loaded to render its chain and money. */
    private const ROW_WITH = ['project:id,name', 'task:id,title', 'approvals.expectedApprover:id,first_name,last_name', 'department:id,name'];

    // ── Module (org-wide) ────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permission::ASSETS_VIEW), 403);

        $rows = ProcurementRequest::with(self::ROW_WITH)
            ->when($request->string('context')->toString(), fn ($q, $c) => $q->where('context', $c))
            ->when($request->string('approval_status')->toString(), fn ($q, $s) => $q->where('approval_status', $s))
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->integer('project_id'), fn ($q, $id) => $q->where('project_id', $id))
            ->when($request->string('q')->toString(), fn ($q, $t) => $q->where('item', 'ilike', "%{$t}%"))
            ->latest('id')->get();

        return response()->json([
            'data'    => $rows->map(fn ($r) => $this->row($r)),
            'options' => $this->options(),
            'summary' => $this->summary($rows),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permission::ASSETS_CREATE), 403);

        $orgId = app('current.organization_id');
        $data = $this->rules($request, $orgId, moduleLevel: true);

        $item = ProcurementRequest::create($data + [
            'organization_id' => $orgId,
            'reference'       => ReferenceSequence::next('PR', 'procurement_requests', $orgId),
            'created_by'      => $request->user()->id,
        ]);

        return response()->json(['data' => $this->row($item->fresh(['project', 'task']))], 201);
    }

    // ── Project-scoped ───────────────────────────────────────────────────────

    public function projectIndex(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $rows = ProcurementRequest::where('project_id', $project->id)->with('task:id,title')->latest('id')->get();

        return response()->json([
            'data'    => $rows->map(fn ($r) => $this->row($r)),
            'options' => $this->options(),
            'summary' => $this->summary($rows),
        ]);
    }

    public function projectStore(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $this->rules($request, $project->organization_id, moduleLevel: false);

        ProcurementRequest::create($data + [
            'organization_id' => $project->organization_id,
            'context'         => 'project',
            'project_id'      => $project->id,
            'reference'       => ReferenceSequence::next('PR', 'procurement_requests', $project->organization_id),
            'created_by'      => $request->user()->id,
        ]);

        return $this->projectIndex($project);
    }

    // ── Shared ───────────────────────────────────────────────────────────────

    public function update(Request $request, ProcurementRequest $procurement): JsonResponse
    {
        $this->authorizeWrite($request, $procurement);

        $procurement->update($this->rules($request, $procurement->organization_id, moduleLevel: true, existing: $procurement));

        return response()->json(['data' => $this->row($procurement->fresh(['project', 'task']))]);
    }

    /**
     * Submit a purchase into its approval chain — the budget is checked here,
     * and a request that would overspend is refused rather than queued.
     */
    public function submit(Request $request, ProcurementRequest $procurement, \App\Services\Procurement\ProcurementApprovalService $approvals): JsonResponse
    {
        $this->authorizeWrite($request, $procurement);

        try {
            $approvals->submit($procurement, $request->user()->id);
        } catch (\RuntimeException $e) {
            // Budget exceeded / no chain configured: something the caller can
            // act on, so a validation error rather than a fault.
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => $this->row($procurement->fresh(self::ROW_WITH))]);
    }

    /**
     * Record a decision.
     *
     * A request carrying a materialised chain is walked stage by stage. One
     * without — raised before this engine existed, or by an automation that
     * never submitted it — keeps the old single-field behaviour, so nothing
     * that was mid-flight at deploy is stranded.
     */
    public function decide(Request $request, ProcurementRequest $procurement, \App\Services\Procurement\ProcurementApprovalService $approvals): JsonResponse
    {
        $this->authorizeWrite($request, $procurement);   // also refuses a closed purchase

        if ($procurement->approvals()->exists()) {
            $data = $request->validate([
                'decision' => ['required', Rule::in(['approve', 'reject'])],
                'comment'  => ['nullable', 'string', 'max:2000'],
                'budget_override_justification' => ['nullable', 'string', 'max:1000'],
            ]);

            abort_unless(
                app(\App\Policies\Procurement\ProcurementApprovalPolicy::class)->decide($request->user(), $procurement),
                403,
                'You may not act on the stage this purchase is waiting at.',
            );

            $override = $data['budget_override_justification'] ?? null;
            if ($override) {
                abort_unless(
                    app(\App\Policies\Procurement\ProcurementApprovalPolicy::class)->overrideBudget($request->user()),
                    403,
                    'Only finance may approve a purchase over budget.',
                );
            }

            try {
                $approvals->decide($procurement, $data['decision'], $request->user()->id, $data['comment'] ?? null, $override);
            } catch (\RuntimeException $e) {
                abort(422, $e->getMessage());
            }

            return response()->json(['data' => $this->row($procurement->fresh(self::ROW_WITH))]);
        }

        // Legacy path — a flat decision on a request with no chain.
        $data = $request->validate([
            'approval_status' => ['required', Rule::in(ProcurementRequest::APPROVAL_STATUSES)],
        ]);
        $decided = $data['approval_status'] !== 'pending';
        $procurement->update($data + [
            'decided_by' => $decided ? $request->user()->id : null,
            'decided_at' => $decided ? now() : null,
        ]);

        return response()->json(['data' => $this->row($procurement->fresh(['project', 'task']))]);
    }

    public function destroy(Request $request, ProcurementRequest $procurement): JsonResponse
    {
        $this->authorizeWrite($request, $procurement);

        $procurement->delete();

        return response()->json(['deleted' => true]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function authorizeWrite(Request $request, ProcurementRequest $procurement): void
    {
        // A closed purchase is settled history. It has been received, paid and
        // posted to the project's accounts, so editing what was bought — or
        // deleting the record — would rewrite the evidence behind money that
        // has already gone out. Every write path lands here, deliberately.
        abort_if($procurement->isClosed(), 422, 'This purchase is closed.');

        if ($procurement->project) {
            $this->authorize('update', $procurement->project);
            return;
        }
        abort_unless($request->user()?->hasPermission(Permission::ASSETS_UPDATE), 403);
    }

    private function rules(Request $request, int $orgId, bool $moduleLevel, ?ProcurementRequest $existing = null): array
    {
        $rules = [
            'item'          => [$existing ? 'sometimes' : 'required', 'string', 'max:200'],
            'category'      => ['nullable', Rule::in(ProcurementRequest::CATEGORIES)],
            'quantity'      => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'unit'          => ['nullable', 'string', 'max:40'],
            'vendor'        => ['nullable', 'string', 'max:160'],
            'vendor_partner_id' => ['nullable', 'integer', Rule::exists('vendor_partners', 'id')->where('organization_id', $orgId)],
            'required_by'   => ['nullable', 'date'],
            'lead_time_days'=> ['nullable', 'integer', 'min:0', 'max:3650'],
            'estimated_cost'=> ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'currency'      => ['sometimes', 'string', 'size:3'],
            'status'        => ['sometimes', Rule::in(ProcurementRequest::STATUSES)],
            'notes'         => ['nullable', 'string', 'max:2000'],
            'task_id'       => ['nullable', 'integer', Rule::exists('tasks', 'id')->where('organization_id', $orgId)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('organization_id', $orgId)],
            'business_justification' => ['nullable', 'string', 'max:2000'],
            'priority'      => ['sometimes', Rule::in(ProcurementRequest::PRIORITIES)],
        ];
        if ($moduleLevel) {
            $rules['context']    = ['sometimes', Rule::in(ProcurementRequest::CONTEXTS)];
            $rules['project_id'] = ['nullable', 'integer', Rule::exists('projects', 'id')->where('organization_id', $orgId)];
        }

        return $request->validate($rules);
    }

    private function options(): array
    {
        return [
            'contexts'          => ProcurementRequest::CONTEXTS,
            'categories'        => ProcurementRequest::CATEGORIES,
            'approval_statuses' => ProcurementRequest::APPROVAL_STATUSES,
            'statuses'          => ProcurementRequest::STATUSES,
        ];
    }

    private function summary($rows): array
    {
        return [
            'count'            => $rows->count(),
            'pending_approval' => $rows->where('approval_status', 'pending')->count(),
            'long_lead'        => $rows->filter(fn ($r) => $r->isLongLead())->count(),
            'estimated_total'  => (float) $rows->sum(fn ($r) => (float) $r->estimated_cost),
        ];
    }

    private function row(ProcurementRequest $r): array
    {
        return [
            'id'              => $r->id,
            'reference'       => $r->reference,
            'context'         => $r->context,
            'lifecycle_stage' => $r->lifecycle_stage,
            'priority'        => $r->priority,
            'business_justification' => $r->business_justification,
            'department'      => $r->relationLoaded('department') && $r->department ? $r->department->name : null,
            'budget'          => [
                'result'    => $r->budget_result,
                'available' => $r->budget_available_amount !== null ? (float) $r->budget_available_amount : null,
                'committed' => $r->budget_committed_amount !== null ? (float) $r->budget_committed_amount : null,
                'checked_at'=> $r->budget_checked_at?->toDateTimeString(),
            ],
            'approvals'       => $r->relationLoaded('approvals') ? $r->approvals->map(fn ($a) => [
                'stage'     => $a->stage,
                'sequence'  => $a->sequence,
                'status'    => $a->status,
                'escalated' => $a->escalated,
                'expected'  => $a->expectedApprover ? trim($a->expectedApprover->first_name . ' ' . $a->expectedApprover->last_name) : null,
                'comment'   => $a->comment,
                'decided_at'=> $a->decided_at?->toDateTimeString(),
            ])->values() : [],
            'project'         => $r->relationLoaded('project') && $r->project ? ['id' => $r->project->id, 'name' => $r->project->name] : null,
            'task'            => $r->relationLoaded('task') && $r->task ? ['id' => $r->task->id, 'title' => $r->task->title] : null,
            'item'            => $r->item,
            'category'        => $r->category,
            'quantity'        => $r->quantity,
            'unit'            => $r->unit,
            'vendor'          => $r->vendor,
            'required_by'     => $r->required_by?->toDateString(),
            'lead_time_days'  => $r->lead_time_days,
            'is_long_lead'    => $r->isLongLead(),
            'estimated_cost'  => $r->estimated_cost,
            'currency'        => $r->currency,
            'approval_status' => $r->approval_status,
            'status'          => $r->status,
            'notes'           => $r->notes,
        ];
    }
}
