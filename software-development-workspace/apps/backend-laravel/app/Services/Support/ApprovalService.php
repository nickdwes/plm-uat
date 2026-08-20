<?php

namespace App\Services\Support;

use App\Models\Support\Approval;
use App\Models\Support\ApprovalStage;
use App\Models\Support\Change;
use App\Models\Support\Ticket;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Asking somebody, and recording what they said.
 *
 * ── Stages are built once, at the point of asking ──
 *
 * The stages that applied when a change was raised are written onto it. A CAB
 * reconfigured next month must not silently add a step to something already
 * half-approved, or remove one that somebody has already answered.
 *
 * ── One stage at a time ──
 *
 * Asking everybody at once gets a faster answer and a worse one: the second
 * approver signs off on something the first has not seen, and the point of a
 * sequence is that each stage knows the previous one agreed.
 */
class ApprovalService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Build the approval chain for a change and ask the first stage.
     *
     * @return int how many stages were raised
     */
    public function requestForChange(Change $change, ?User $actor = null): int
    {
        if (! $change->needsApproval()) {
            /*
             * A standard change is pre-approved by definition. Asking anyway
             * trains people that approvals are a formality, which is how the
             * ones that matter stop being read.
             */
            $change->forceFill(['status' => Change::APPROVED])->save();

            return 0;
        }

        $stages = ApprovalStage::withoutGlobalScope('organization')
            ->where('organization_id', $change->organization_id)
            ->where('is_active', true)
            ->where('applies_to', ApprovalStage::FOR_CHANGE)
            ->orderBy('sequence')->orderBy('id')
            ->get()
            ->filter(fn (ApprovalStage $s) => $s->appliesToChange($change));

        if ($stages->isEmpty()) {
            throw new RuntimeException(
                'No approval stage covers a ' . $change->risk . '-risk change. '
                . 'Nothing would be asked, and the change would sit waiting for a decision '
                . 'nobody has been asked to make. Add a stage first.',
            );
        }

        return DB::transaction(function () use ($change, $stages, $actor) {
            $change->approvals()->delete();

            foreach ($stages->values() as $i => $stage) {
                Approval::create([
                    'organization_id'      => $change->organization_id,
                    'approvable_type'      => Change::class,
                    'approvable_id'        => $change->id,
                    'stage'                => $stage->stage,
                    'sequence'             => $i + 1,
                    'expected_approver_id' => $stage->approver_id,
                ]);
            }

            $change->forceFill(['status' => Change::AWAITING])->save();

            $this->notifyNext($change);

            return $stages->count();
        });
    }

    /** The same, for a catalogue request that needs signing off. */
    public function requestForTicket(Ticket $ticket, ?int $serviceId): int
    {
        $stages = ApprovalStage::withoutGlobalScope('organization')
            ->where('organization_id', $ticket->organization_id)
            ->where('is_active', true)
            ->where('applies_to', ApprovalStage::FOR_REQUEST)
            ->orderBy('sequence')->orderBy('id')
            ->get()
            ->filter(fn (ApprovalStage $s) => $s->appliesToService($serviceId));

        if ($stages->isEmpty()) {
            return 0;
        }

        foreach ($stages->values() as $i => $stage) {
            Approval::create([
                'organization_id'      => $ticket->organization_id,
                'approvable_type'      => Ticket::class,
                'approvable_id'        => $ticket->id,
                'stage'                => $stage->stage,
                'sequence'             => $i + 1,
                'expected_approver_id' => $stage->approver_id,
            ]);
        }

        return $stages->count();
    }

    /**
     * Record a decision.
     *
     * A rejection needs a reason. "Rejected" on its own sends the requester
     * back to ask what was wrong, which is the conversation the approval was
     * supposed to have already had.
     */
    public function decide(Approval $approval, string $decision, ?string $comment, User $actor): Approval
    {
        if (! $approval->isPending()) {
            throw new RuntimeException('That has already been decided.');
        }

        if ($decision === Approval::REJECTED && blank($comment)) {
            throw new RuntimeException(
                'Say why. A rejection with no reason sends this back for somebody to guess at.',
            );
        }

        /*
         * Out of order is refused. A later stage approving before an earlier
         * one has answered is somebody signing off on a thing the previous
         * approver may still reject.
         */
        $earlier = Approval::where('approvable_type', $approval->approvable_type)
            ->where('approvable_id', $approval->approvable_id)
            ->where('sequence', '<', $approval->sequence)
            ->where('status', Approval::PENDING)
            ->exists();

        if ($earlier) {
            throw new RuntimeException('An earlier stage has not answered yet.');
        }

        return DB::transaction(function () use ($approval, $decision, $comment, $actor) {
            $approval->forceFill([
                'status'     => $decision,
                'comment'    => $comment,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ])->save();

            $subject = $approval->approvable;

            if ($decision === Approval::REJECTED) {
                /*
                 * One rejection ends it. Carrying on to ask the remaining
                 * stages wastes their time on something already refused, and
                 * the outstanding requests would sit in their queue forever.
                 */
                Approval::where('approvable_type', $approval->approvable_type)
                    ->where('approvable_id', $approval->approvable_id)
                    ->where('status', Approval::PENDING)
                    ->update(['status' => Approval::SKIPPED]);

                if ($subject instanceof Change) {
                    $subject->forceFill(['status' => Change::REJECTED])->save();
                }

                return $approval->fresh();
            }

            if ($subject instanceof Change && $this->isFullyApproved($subject)) {
                $subject->forceFill([
                    'status' => $subject->window_start ? Change::SCHEDULED : Change::APPROVED,
                ])->save();
            } elseif ($subject instanceof Change) {
                $this->notifyNext($subject);
            }

            return $approval->fresh();
        });
    }

    public function isFullyApproved(Model $subject): bool
    {
        return ! Approval::where('approvable_type', $subject::class)
            ->where('approvable_id', $subject->getKey())
            ->where('status', Approval::PENDING)
            ->exists();
    }

    /** What is waiting on this person. */
    public function pendingFor(User $user, int $organizationId): array
    {
        return Approval::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('status', Approval::PENDING)
            ->where(fn ($q) => $q->where('expected_approver_id', $user->id)
                ->orWhereNull('expected_approver_id'))
            ->with('approvable')
            ->orderBy('sequence')
            ->get()
            ->filter(function (Approval $a) {
                // Only the stage that is actually next. Showing a third-stage
                // approver something the first has not seen invites them to
                // decide it out of order.
                return ! Approval::where('approvable_type', $a->approvable_type)
                    ->where('approvable_id', $a->approvable_id)
                    ->where('sequence', '<', $a->sequence)
                    ->where('status', Approval::PENDING)
                    ->exists();
            })
            ->values()
            ->all();
    }

    private function notifyNext(Change $change): void
    {
        $next = $change->approvals()
            ->where('status', Approval::PENDING)
            ->orderBy('sequence')
            ->first();

        if (! $next?->expected_approver_id) {
            return;
        }

        try {
            $this->notifications->create(
                organizationId: (string) $change->organization_id,
                userId:         $next->expected_approver_id,
                type:           'support',
                title:          "{$change->reference} needs your approval",
                message:        $change->title,
                data:           ['change_id' => $change->id, 'stage' => $next->stage],
            );
        } catch (\Throwable) {
            // An approval that cannot be notified is still an approval that
            // exists; the record above is the part that matters.
        }
    }
}
