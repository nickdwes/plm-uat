<?php

namespace App\Services\Vendor;

use App\Models\Training\VendorPartner;
use App\Models\Vendor\VendorApproval;
use App\Models\Vendor\VendorApprovalStage;
use App\Services\Audit\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Getting a supplier from typed-in to tradeable.
 *
 * Three things this enforces that nothing did before:
 *
 *  1. **The states are a map, not a column.** `approve` used to work from
 *     `draft`, so the review everybody believed was happening could be
 *     skipped by calling one endpoint. A transition the map does not name is
 *     refused with the reason.
 *  2. **Nobody signs their own submission.** The point of a review is a
 *     second pair of eyes; without this the chain is theatre.
 *  3. **Nothing is activated while it cannot legally trade.** Submission and
 *     final approval both consult `VendorReadiness`, so a supplier missing
 *     its tax certificate cannot become approved and then quietly receive a
 *     purchase order.
 */
class VendorOnboarding
{
    public function __construct(
        private readonly VendorReadiness $readiness,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Which states each state may become.
     *
     * Written out rather than implied so that reading it answers "can this
     * happen?" without tracing five call sites. `rejected → draft` exists
     * because a refusal should be fixable; `approved → draft` does not,
     * because unpicking a live supplier is a suspension, not an edit.
     */
    public const TRANSITIONS = [
        'draft'          => ['pending_review'],
        'pending_review' => ['approved', 'rejected', 'draft'],
        'approved'       => ['suspended'],
        'suspended'      => ['approved', 'rejected'],
        'rejected'       => ['draft'],
    ];

    private function assertMayMove(VendorPartner $vendor, string $to): void
    {
        $from    = $vendor->onboarding_status ?? 'draft';
        $allowed = self::TRANSITIONS[$from] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw new RuntimeException(
                "A vendor that is {$this->readable($from)} cannot become {$this->readable($to)}."
            );
        }
    }

    private function readable(string $status): string
    {
        return str_replace('_', ' ', $status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Submission
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Put a draft into the chain.
     *
     * Refused while anything mandatory is missing or expired: a review that
     * begins on incomplete paperwork ends with somebody approving it anyway.
     */
    public function submit(VendorPartner $vendor, int $actorId): VendorPartner
    {
        $this->assertMayMove($vendor, 'pending_review');

        $vendor->load(['stakeholderRoles', 'documents']);
        $blockers = $this->readiness->blockers($vendor);

        if ($blockers !== []) {
            throw new RuntimeException(
                'This vendor cannot be submitted yet: ' . implode('; ', $blockers) . '.'
            );
        }

        return DB::transaction(function () use ($vendor, $actorId) {
            // Materialised from the configured chain at this moment, so a
            // later reconfiguration cannot rewrite what was asked here.
            // Through the resolver, so an organisation that has never
            // configured a chain still gets the default one rather than a
            // refusal blaming it for something nobody could have set up.
            $stages = VendorApprovalStage::forOrganization($vendor->organization_id);

            if ($stages->isEmpty()) {
                throw new RuntimeException('Every stage of this organisation’s approval chain is switched off.');
            }

            // A resubmission starts a clean chain; the old rows would
            // otherwise read as decisions on this attempt.
            $vendor->approvals()->delete();

            foreach ($stages as $stage) {
                VendorApproval::create([
                    'organization_id'   => $vendor->organization_id,
                    'vendor_partner_id' => $vendor->id,
                    'stage'             => $stage->stage,
                    'sequence'          => $stage->sequence,
                    'status'            => 'pending',
                ]);
            }

            $vendor->forceFill([
                'onboarding_status' => 'pending_review',
                'submitted_by'      => $actorId,
                'submitted_at'      => now(),
                'rejection_reason'  => null,
            ])->save();

            $this->notifyApprovers($vendor, 'vendor.awaiting_review',
                "{$vendor->name} is awaiting review",
                'Submitted for onboarding approval.');

            return $vendor->fresh(['approvals', 'stakeholderRoles']);
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Decisions
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Sign, or refuse, the stage that is next.
     *
     * Out-of-order decisions are refused rather than reordered: a chain whose
     * stages can be signed in any order is a list, not a chain.
     */
    public function decide(
        VendorPartner $vendor,
        string $decision,
        int $actorId,
        ?string $comment = null,
    ): VendorPartner {
        $approval = $vendor->pendingApproval();

        if (! $approval) {
            throw new RuntimeException('This vendor is not awaiting a decision.');
        }

        $this->assertNotTheSubmitter($vendor, $actorId);

        if ($decision !== 'approve') {
            if (! $comment) {
                throw new RuntimeException('Say why the vendor is being refused.');
            }

            return $this->reject($vendor, $approval, $actorId, $comment);
        }

        // The last stage is the activation gate, so the paperwork is checked
        // again here: documents expire between submission and signature, and
        // the whole point is that an expired certificate stops a supplier.
        if ($this->isLastStage($vendor, $approval)) {
            $vendor->load(['stakeholderRoles', 'documents']);
            $blockers = $this->readiness->blockers($vendor);

            if ($blockers !== []) {
                throw new RuntimeException(
                    'This vendor cannot be approved: ' . implode('; ', $blockers) . '.'
                );
            }
        }

        return DB::transaction(function () use ($vendor, $approval, $actorId, $comment) {
            $approval->update([
                'status'     => 'approved',
                'decided_by' => $actorId,
                'comment'    => $comment,
                'decided_at' => now(),
            ]);

            if ($vendor->approvals()->where('status', 'pending')->exists()) {
                $this->notifyApprovers($vendor, 'vendor.awaiting_review',
                    "{$vendor->name} is awaiting the next review stage",
                    "{$approval->label()} signed. The next stage is now open.");

                return $vendor->fresh('approvals');
            }

            $vendor->forceFill([
                'onboarding_status' => 'approved',
                'is_active'         => true,
                'approved_at'       => now(),
                'approved_by'       => $actorId,
                'rejection_reason'  => null,
            ])->save();

            return $vendor->fresh('approvals');
        });
    }

    private function reject(VendorPartner $vendor, VendorApproval $approval, int $actorId, string $reason): VendorPartner
    {
        return DB::transaction(function () use ($vendor, $approval, $actorId, $reason) {
            $approval->update([
                'status'     => 'rejected',
                'decided_by' => $actorId,
                'comment'    => $reason,
                'decided_at' => now(),
            ]);

            // Everything downstream is moot; marked skipped rather than left
            // pending so the chain does not read as still in progress.
            $vendor->approvals()->where('status', 'pending')->update(['status' => 'skipped']);

            $vendor->forceFill([
                'onboarding_status' => 'rejected',
                'is_active'         => false,
                'rejection_reason'  => $reason,
            ])->save();

            return $vendor->fresh('approvals');
        });
    }

    /** Take a live supplier out of use, without deciding it was a mistake. */
    public function suspend(VendorPartner $vendor, string $reason): VendorPartner
    {
        $this->assertMayMove($vendor, 'suspended');

        $vendor->forceFill([
            'onboarding_status' => 'suspended',
            'is_active'         => false,
            'rejection_reason'  => $reason,
        ])->save();

        return $vendor->fresh();
    }

    /**
     * Put a suspended supplier back into use.
     *
     * Its paperwork is checked again: a suspension usually outlives a
     * certificate, and restoring a vendor whose insurance lapsed meanwhile is
     * exactly the mistake this increment exists to stop.
     */
    public function reactivate(VendorPartner $vendor): VendorPartner
    {
        $this->assertMayMove($vendor, 'approved');

        $vendor->load(['stakeholderRoles', 'documents']);
        $blockers = $this->readiness->blockers($vendor);

        if ($blockers !== []) {
            throw new RuntimeException(
                'This vendor cannot be reactivated: ' . implode('; ', $blockers) . '.'
            );
        }

        $vendor->forceFill([
            'onboarding_status' => 'approved',
            'is_active'         => true,
            'rejection_reason'  => null,
        ])->save();

        return $vendor->fresh();
    }

    /** Send a rejected vendor back to draft so it can be corrected. */
    public function returnToDraft(VendorPartner $vendor): VendorPartner
    {
        $this->assertMayMove($vendor, 'draft');

        $vendor->forceFill(['onboarding_status' => 'draft'])->save();

        return $vendor->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Sensitive changes
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The fields a second person has to confirm.
     *
     * Redirecting the bank account of a supplier everyone already trusts is
     * the highest-value fraud in a purchase-to-pay system, and changing the
     * legal identity is how a shell company inherits an approved vendor's
     * history. Everything else — address, phone, notes — saves normally.
     */
    public const SENSITIVE_FIELDS = [
        'name', 'registration_number', 'tax_id',
        'bank_name', 'bank_branch', 'account_name', 'account_number', 'swift_code',
    ];

    /**
     * Split an update into what may be applied and what must wait.
     *
     * Only on an APPROVED vendor: while it is still a draft nobody is paying
     * it, so asking for two signatures to fix a typo would be ceremony.
     *
     * @return array{apply: array, pending: array}
     */
    public function partitionUpdate(VendorPartner $vendor, array $data): array
    {
        if ($vendor->onboarding_status !== 'approved') {
            return ['apply' => $data, 'pending' => []];
        }

        $pending = [];

        foreach (self::SENSITIVE_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            // Unchanged values are not changes — sending the whole form back
            // should not queue a confirmation for fields nobody touched.
            if ((string) $data[$field] === (string) $vendor->{$field}) {
                unset($data[$field]);
                continue;
            }

            $pending[$field] = $data[$field];
            unset($data[$field]);
        }

        return ['apply' => $data, 'pending' => $pending];
    }

    public function queueSensitiveChange(VendorPartner $vendor, array $pending, int $actorId): void
    {
        $vendor->forceFill([
            'pending_change'    => $pending,
            'pending_change_by' => $actorId,
            'pending_change_at' => now(),
        ])->save();

        AuditLogger::record(
            action: 'sensitive_change_proposed',
            auditable: $vendor,
            // The fields, never the values: an audit line quoting the new
            // account number is a second copy of it.
            description: "Proposed change to " . implode(', ', array_keys($pending)) . " on \"{$vendor->name}\" — awaiting confirmation",
            organizationId: $vendor->organization_id,
        );

        $this->notifyApprovers($vendor, 'vendor.change_awaiting_confirmation',
            "{$vendor->name}: a sensitive change needs confirming",
            'Banking or legal identity details were changed and are waiting for a second pair of eyes.');
    }

    /**
     * Apply a queued change — never by the person who proposed it.
     */
    public function confirmSensitiveChange(VendorPartner $vendor, int $actorId): VendorPartner
    {
        $pending = $vendor->pending_change;

        if (empty($pending)) {
            throw new RuntimeException('There is no change waiting to be confirmed.');
        }

        if ((int) $vendor->pending_change_by === $actorId) {
            throw new RuntimeException('A change has to be confirmed by somebody other than the person who made it.');
        }

        $vendor->forceFill([
            ...$pending,
            'pending_change'    => null,
            'pending_change_by' => null,
            'pending_change_at' => null,
        ])->save();

        AuditLogger::record(
            action: 'sensitive_change_confirmed',
            auditable: $vendor,
            description: "Confirmed change to " . implode(', ', array_keys($pending)) . " on \"{$vendor->name}\"",
            organizationId: $vendor->organization_id,
        );

        return $vendor->fresh();
    }

    public function discardSensitiveChange(VendorPartner $vendor, int $actorId): VendorPartner
    {
        if (empty($vendor->pending_change)) {
            throw new RuntimeException('There is no change waiting to be confirmed.');
        }

        $fields = array_keys($vendor->pending_change);

        $vendor->forceFill([
            'pending_change'    => null,
            'pending_change_by' => null,
            'pending_change_at' => null,
        ])->save();

        AuditLogger::record(
            action: 'sensitive_change_discarded',
            auditable: $vendor,
            description: "Discarded proposed change to " . implode(', ', $fields) . " on \"{$vendor->name}\"",
            organizationId: $vendor->organization_id,
        );

        return $vendor->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Separation of duties.
     *
     * Enforced here rather than in a policy because it is a property of this
     * vendor's history, not of the caller's role: the same person may
     * legitimately approve every other supplier in the register.
     */
    private function assertNotTheSubmitter(VendorPartner $vendor, int $actorId): void
    {
        if ($vendor->submitted_by !== null && (int) $vendor->submitted_by === $actorId) {
            throw new RuntimeException('A vendor has to be reviewed by somebody other than whoever submitted it.');
        }
    }

    private function isLastStage(VendorPartner $vendor, VendorApproval $approval): bool
    {
        return ! $vendor->approvals()
            ->where('status', 'pending')
            ->where('sequence', '>', $approval->sequence)
            ->exists();
    }

    /** Whoever may decide vendors in this tenant hears about it. */
    private function notifyApprovers(VendorPartner $vendor, string $type, string $title, string $message): void
    {
        foreach ($this->approverIds($vendor->organization_id) as $userId) {
            // A notification failure must never roll back the decision that
            // caused it — the same guard Support's ApprovalService uses.
            try {
                $this->notifications->create(
                    organizationId: (string) $vendor->organization_id,
                    userId: $userId,
                    type: $type,
                    title: $title,
                    message: $message,
                    data: ['vendor_id' => $vendor->id],
                    notifiableType: VendorPartner::class,
                    notifiableId: (string) $vendor->id,
                );
            } catch (\Throwable) {
                // Deliberately swallowed.
            }
        }
    }

    private function approverIds(int $organizationId): array
    {
        return \App\Models\User::query()
            ->whereHas('roles', fn ($q) => $q
                ->where('user_roles.organization_id', $organizationId)
                // Group-scoped assignments name no organisation; asking only
                // for this one skips every group administrator.
                ->orWhereNull('user_roles.organization_id'))
            ->get()
            ->filter(fn ($u) => $u->hasPermission(\App\Support\Authorization\Permission::VENDORS_APPROVE, $organizationId))
            ->pluck('id')
            ->all();
    }
}
