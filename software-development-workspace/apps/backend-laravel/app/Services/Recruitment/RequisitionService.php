<?php

namespace App\Services\Recruitment;

use App\Models\Employee;
use App\Models\Recruitment\RequisitionApproval;
use App\Models\Recruitment\RequisitionApprovalPolicy;
use App\Models\Recruitment\RequisitionApprovalStep;
use App\Models\Recruitment\RequisitionEvent;
use App\Models\Recruitment\TalentRequisition;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Submitting a requisition and moving it through its approvals.
 *
 * The chain is sequential and that is deliberate. A CTO approving something HR
 * has not seen is not a chain, it is three people with opinions — and the
 * reason a requisition exists at all is that somebody wanted the opinions in a
 * particular order.
 */
class RequisitionService
{
    public function __construct(private NotificationService $notifications)
    {
    }

    /**
     * The next reference for the year: Req-2026-0048.
     *
     * Numbered per year and per organisation, under an advisory lock, because
     * two people submitting at once must not both be told they are 0048. The
     * numeric tail is matched with a regex so a hand-typed reference cannot
     * poison the sequence.
     */
    public function nextReference(int $organizationId, ?int $year = null): string
    {
        $year   = $year ?? (int) now()->format('Y');
        $prefix = "Req-{$year}";

        return DB::transaction(function () use ($organizationId, $prefix) {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ["requisitions:{$prefix}:{$organizationId}"]);

            $highest = DB::table('talent_requisitions')
                ->where('organization_id', $organizationId)
                ->where('reference', 'like', $prefix . '-%')
                ->selectRaw(
                    "MAX(CASE WHEN reference ~ ? THEN substring(reference from '[0-9]+$')::bigint END) AS n",
                    ['^' . preg_quote($prefix, '/') . '-[0-9]+$'],
                )
                ->value('n');

            return $prefix . '-' . str_pad((string) ((int) $highest + 1), 4, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Send a requisition for approval.
     *
     * The chain is built here from the policy and then left alone. Reading it
     * through the policy would mean rewriting the policy next quarter rewrites
     * who approved what last quarter.
     */
    public function submit(TalentRequisition $requisition): TalentRequisition
    {
        $policy = RequisitionApprovalPolicy::forDepartment(
            $requisition->organization_id,
            $requisition->department_id,
        );

        if ($policy === null || $policy->steps->isEmpty()) {
            /*
             * Refused rather than auto-approved. A requisition nobody has to
             * agree to is a vacancy with extra steps, and silently approving
             * would make the control look present while doing nothing.
             */
            throw ValidationException::withMessages([
                'department_id' => 'No approval chain is configured for this department. Set one up before raising requisitions.',
            ]);
        }

        DB::transaction(function () use ($requisition, $policy) {
            $requisition->approvals()->delete();

            foreach ($policy->steps as $step) {
                $requisition->approvals()->create([
                    'organization_id' => $requisition->organization_id,
                    'position' => $step->position,
                    'label'    => $step->label,
                    'employee_id' => $this->resolveApprover($step, $requisition),
                    'status'   => 'pending',
                ]);
            }

            $requisition->update(['status' => 'in_approval', 'decision_reason' => null]);

            $this->record($requisition, 'requested', actor: $requisition->requested_by);
            $this->record($requisition, 'in_approval');
        });

        $this->tellCurrentApprover($requisition->fresh('approvals'));

        return $requisition->fresh(['approvals.approver', 'events']);
    }

    /**
     * Approve, hold or decline the step in front of you.
     *
     * Only the current step's approver may act. Anything else and the order
     * the chain was written in stops meaning anything.
     */
    public function decide(
        TalentRequisition $requisition,
        Employee $actor,
        string $decision,
        ?string $reason = null,
    ): TalentRequisition {
        $step = $requisition->approvals->firstWhere('status', 'pending');

        abort_if($step === null, 422, 'There is nothing waiting for a decision on this requisition.');
        abort_unless($step->employee_id === $actor->id, 403, 'This step is not yours to decide.');

        if (in_array($decision, ['held', 'declined'], true) && ! filled($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'Say why. Somebody has to act on this.',
            ]);
        }

        DB::transaction(function () use ($requisition, $step, $actor, $decision, $reason) {
            $step->update([
                'status' => $decision,
                'reason' => $reason,
                'actioned_at' => now(),
            ]);

            if ($decision === 'approved') {
                $remaining = $requisition->approvals()->awaiting()->exists();

                if ($remaining) {
                    // Still somebody left; the requisition stays in approval.
                    $this->record($requisition, 'in_approval', actor: $actor->id);

                    return;
                }

                $requisition->update(['status' => 'approved', 'decision_reason' => null]);
                $this->record($requisition, 'approved', actor: $actor->id);

                return;
            }

            /*
             * Held is not declined. A hold pauses the chain with the step still
             * answerable later; a decline ends it. Collapsing the two would
             * mean every paused requisition looks like a refusal in reporting.
             */
            $requisition->update([
                'status' => $decision === 'held' ? 'on_hold' : 'declined',
                'decision_reason' => $reason,
            ]);

            $this->record($requisition, $decision === 'held' ? 'on_hold' : 'declined', $reason, $actor->id);
        });

        $fresh = $requisition->fresh(['approvals.approver', 'events']);

        if ($decision === 'approved' && $fresh->status === 'in_approval') {
            $this->tellCurrentApprover($fresh);
        } else {
            $this->tellRequester($fresh, $decision, $reason);
        }

        return $fresh;
    }

    /**
     * Put a held requisition back in front of its approver.
     *
     * The step that held it becomes pending again rather than being skipped —
     * a hold is a question, and resuming past the person who asked it would
     * make holding pointless.
     */
    public function resume(TalentRequisition $requisition, Employee $actor): TalentRequisition
    {
        abort_unless($requisition->status === 'on_hold', 422, 'That requisition is not on hold.');

        $held = $requisition->approvals()->where('status', 'held')->orderBy('position')->first();

        DB::transaction(function () use ($requisition, $held, $actor) {
            $held?->update(['status' => 'pending', 'actioned_at' => null]);

            $requisition->update(['status' => 'in_approval', 'decision_reason' => null]);
            $this->record($requisition, 'in_approval', actor: $actor->id);
        });

        $fresh = $requisition->fresh(['approvals.approver', 'events']);
        $this->tellCurrentApprover($fresh);

        return $fresh;
    }

    // ── Plumbing ──────────────────────────────────────────────────────────

    /**
     * Who fills this step for this requisition.
     *
     * Null where nobody can be found — a step with no approver is visible and
     * answerable by an administrator, which is better than quietly dropping
     * the rung and telling everyone the chain was followed.
     */
    private function resolveApprover(RequisitionApprovalStep $step, TalentRequisition $requisition): ?int
    {
        return match ($step->resolver) {
            'employee' => $step->employee_id,

            // Worked out from whoever asked, so it survives a reorganisation.
            'line_manager' => Employee::whereKey($requisition->requested_by)->value('reports_to'),

            'role' => $step->role_id === null ? null : Employee::query()
                ->where('primary_organization_id', $requisition->organization_id)
                ->whereHas('user.roles', fn ($r) => $r->where('roles.id', $step->role_id))
                ->value('id'),

            default => null,
        };
    }

    private function record(
        TalentRequisition $requisition,
        string $status,
        ?string $note = null,
        ?int $actor = null,
    ): void {
        RequisitionEvent::create([
            'organization_id' => $requisition->organization_id,
            'talent_requisition_id' => $requisition->id,
            'status' => $status,
            'note'   => $note,
            'actor_employee_id' => $actor,
            'occurred_at' => now(),
        ]);
    }

    private function tellCurrentApprover(TalentRequisition $requisition): void
    {
        $step = $requisition->approvals->firstWhere('status', 'pending');

        if ($step?->employee_id === null) {
            return;
        }

        $this->notify(
            $step->employee_id,
            $requisition,
            'requisition.awaiting_approval',
            'A requisition needs your approval',
            "{$requisition->reference} — {$requisition->job_title}",
        );
    }

    private function tellRequester(TalentRequisition $requisition, string $decision, ?string $reason): void
    {
        $titles = [
            'approved' => 'Your requisition was approved',
            'held'     => 'Your requisition was put on hold',
            'declined' => 'Your requisition was declined',
        ];

        $this->notify(
            $requisition->requested_by,
            $requisition,
            'requisition.' . $decision,
            $titles[$decision] ?? 'Your requisition was updated',
            $reason ?: "{$requisition->reference} — {$requisition->job_title}",
        );
    }

    /** Notifications reach users; requisitions name employees. */
    private function notify(?int $employeeId, TalentRequisition $r, string $type, string $title, string $message): void
    {
        if ($employeeId === null) {
            return;
        }

        $userId = Employee::whereKey($employeeId)->value('user_id');

        if ($userId === null) {
            return;
        }

        $this->notifications->create(
            (string) $r->organization_id,
            $userId,
            $type,
            $title,
            $message,
            ['requisition_id' => $r->id, 'reference' => $r->reference],
            TalentRequisition::class,
            (string) $r->id,
        );
    }
}
