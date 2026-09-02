<?php

namespace App\Services\Procurement;

use App\Models\Procurement\ProcurementApproval;
use App\Models\Procurement\ProcurementApprovalStage;
use App\Models\Procurement\ProcurementRequest;
use App\Services\NotificationService;
use App\Services\Travel\TravelPlacementResolver;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The purchase approval chain: who must sign, in what order, and what happens
 * when they do.
 *
 * The stages are configuration (see ProcurementApprovalStage) filtered by the
 * amount, so the ladder a purchase climbs is decided by its value rather than
 * by code. Submitting materialises one row per applicable stage; decisions walk
 * them earliest-first so a later approver cannot sign ahead of an earlier one;
 * a rejection skips the rest rather than leaving them in somebody's queue.
 *
 * The legacy `approval_status` column is kept in step throughout — the unified
 * project approval queue and the alert feed read it, and neither should have to
 * know this engine exists.
 */
class ProcurementApprovalService
{
    public function __construct(
        private TravelPlacementResolver $placement,
        private ProcurementBudgetService $budget,
        private NotificationService $notifications,
    ) {
    }

    /**
     * The stages that apply to this request: the category-specific chain if the
     * organisation configured one, otherwise the default chain, each filtered
     * by its amount threshold.
     */
    public function stagesFor(ProcurementRequest $request)
    {
        $all = ProcurementApprovalStage::where('organization_id', $request->organization_id)
            ->where('is_active', true)->orderBy('sequence')->get();

        $specific = $all->where('category', $request->category);

        // A category chain replaces the default rather than adding to it, so a
        // configuration reads as one ladder and not two merged behind the scenes.
        $applicable = $specific->isNotEmpty() ? $specific : $all->whereNull('category');

        return $applicable
            ->filter(fn (ProcurementApprovalStage $s) => $s->appliesTo($request->committedAmount(), strtoupper($request->currency ?: 'KES')))
            ->values();
    }

    /**
     * Submit for approval: check the budget, build the chain, notify the first
     * approver.
     *
     * @throws RuntimeException when the budget is exceeded or no chain applies
     */
    public function submit(ProcurementRequest $request, ?int $actorId = null): ProcurementRequest
    {
        if ($request->isClosed()) {
            throw new RuntimeException('This purchase is closed.');
        }

        if ($request->approvals()->where('status', 'pending')->exists()) {
            throw new RuntimeException('This request is already awaiting approval.');
        }

        $verdict = $this->budget->check($request);
        if (! $verdict['ok']) {
            // Blocked here rather than discovered at invoice time. Finance can
            // still let it through, but only deliberately, at the decision.
            throw new RuntimeException($verdict['message'] ?? 'Budget exceeded.');
        }

        $stages = $this->stagesFor($request);
        if ($stages->isEmpty()) {
            // A chain that resolves to nothing would let a purchase approve
            // itself. Refused, rather than found out when it is paid.
            throw new RuntimeException('No approval stages are configured for this organisation.');
        }

        return DB::transaction(function () use ($request, $stages, $actorId) {
            $placement = $this->placementFor($request);
            $request->approvals()->delete();

            foreach ($stages as $stage) {
                $request->approvals()->create([
                    'organization_id'      => $request->organization_id,
                    'stage'                => $stage->stage,
                    'sequence'             => $stage->sequence,
                    'expected_approver_id' => $this->placement->approverFor($stage->stage, $placement),
                    'status'               => 'pending',
                ]);
            }

            // A purchase that eats past the margin floor answers to an
            // executive even when its amount alone would not have asked one.
            if ($this->budget->breachesMarginFloor($request) && ! $stages->contains('stage', 'executive')) {
                $request->approvals()->create([
                    'organization_id' => $request->organization_id,
                    'stage'           => 'executive',
                    'sequence'        => (int) $stages->max('sequence') + 1,
                    'status'          => 'pending',
                    'escalated'       => true,
                ]);
            }

            $request->update([
                'lifecycle_stage' => 'submitted',
                'submitted_at'    => now(),
                'approval_status' => 'pending',
            ]);

            $this->notifyNext($request->fresh('approvals'), $actorId);

            return $request->fresh('approvals');
        });
    }

    /**
     * Record a decision on the stage currently awaiting one.
     *
     * `$overrideJustification` is the finance override: an approver who holds
     * the finance permission may pass a request the budget gate refuses, but
     * only by saying why — the reason lands in the approval comment and the
     * audit trail.
     */
    public function decide(
        ProcurementRequest $request,
        string $decision,
        int $userId,
        ?string $comment = null,
        ?string $overrideJustification = null,
    ): ProcurementRequest {
        $approval = $request->pendingApproval();

        if (! $approval) {
            throw new RuntimeException('This request is not awaiting approval.');
        }

        if ($decision === 'approve') {
            $verdict = $this->budget->check($request);
            if (! $verdict['ok']) {
                if (! $overrideJustification) {
                    // The numbers move between stages, so the gate is checked
                    // again at the decision, not only at submission.
                    throw new RuntimeException($verdict['message'] ?? 'Budget exceeded.');
                }
                $request->forceFill(['budget_result' => ProcurementRequest::BUDGET_OVERRIDDEN])->save();
                $comment = trim(($comment ? $comment . ' — ' : '') . 'Budget override: ' . $overrideJustification);
            }
        }

        return DB::transaction(function () use ($request, $approval, $decision, $userId, $comment) {
            $approval->update([
                'status'     => $decision === 'approve' ? 'approved' : 'rejected',
                'decided_by' => $userId,
                'comment'    => $comment,
                'decided_at' => now(),
            ]);

            if ($decision !== 'approve') {
                $request->approvals()->where('status', 'pending')->update(['status' => 'skipped']);
                $request->update([
                    'lifecycle_stage' => 'rejected',
                    'approval_status' => 'rejected',
                    'decided_by'      => $userId,
                    'decided_at'      => now(),
                ]);

                return $request->fresh('approvals');
            }

            if ($request->approvals()->where('status', 'pending')->exists()) {
                $this->notifyNext($request->fresh('approvals'), $userId);

                return $request->fresh('approvals');
            }

            // Every stage has signed: the legacy summary column agrees, so the
            // project approval queue and alerts need to know nothing of this.
            $request->update([
                'lifecycle_stage' => 'approved',
                'approval_status' => 'approved',
                'decided_by'      => $userId,
                'decided_at'      => now(),
            ]);

            return $request->fresh('approvals');
        });
    }

    /** Where the requester sits, for resolving structural approvers. */
    private function placementFor(ProcurementRequest $request): array
    {
        $employeeId = $request->creator?->employeeId();

        $placement = $employeeId
            ? $this->placement->placement($employeeId, $request->organization_id)
            : ['department_id' => null, 'functional_unit_id' => null, 'job_grade_id' => null];

        // A request that names its own department answers to that department's
        // lead, whoever happened to type it.
        if ($request->department_id) {
            $placement['department_id'] = $request->department_id;
            $placement['functional_unit_id'] = DB::table('departments')
                ->where('id', $request->department_id)->value('functional_unit_id');
        }

        return $placement;
    }

    /** Tell the next approver there is something waiting, when one is named. */
    private function notifyNext(ProcurementRequest $request, ?int $actorId): void
    {
        $approval = $request->pendingApproval();
        $userId = $approval?->expectedApprover?->user?->id;

        if (! $approval || ! $userId || $userId === $actorId) {
            return;
        }

        $this->notifications->create(
            (string) $request->organization_id,
            $userId,
            'procurement.approval_pending',
            'Purchase approval needed',
            "“{$request->item}” needs your sign-off as " . str_replace('_', ' ', $approval->stage) . '.',
            ['procurement_request_id' => $request->id, 'stage' => $approval->stage],
            ProcurementRequest::class,
            (string) $request->id,
        );
    }
}
