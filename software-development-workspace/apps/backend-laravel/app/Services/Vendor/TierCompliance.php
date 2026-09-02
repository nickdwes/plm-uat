<?php

namespace App\Services\Vendor;

use App\Models\Training\EmployeeTrainingRecord;
use App\Models\Training\VendorPartner;
use App\Models\Vendor\VendorPartnershipTier;
use App\Models\Vendor\VendorTierRequirement;

/**
 * Are we still entitled to the tier we say we hold?
 *
 * The question a partner manager is actually asked, and the one the old tier
 * record could not answer: it stored "3" under min_certifications without
 * saying which three, so the only way to know was to ring the OEM. A tier now
 * names its training, assessments and certifications, and this counts the
 * people who hold each — from the same training records the rest of the
 * system already keeps.
 *
 * Two halves, because programmes have two halves: the people obligation
 * (certified headcount per named item) and the commercial one (a revenue
 * commitment and the seats it covers). A tier can be short on either.
 */
class TierCompliance
{
    /** Certifications inside this window are worth warning about, not failing. */
    private const EXPIRING_SOON_DAYS = 60;

    /**
     * One tier, with every requirement resolved.
     *
     * @return array{tier: array, requirements: array, commercial: ?array, status: string, gaps: string[]}
     */
    public function forTier(VendorPartnershipTier $tier): array
    {
        $tier->loadMissing(['tierRequirements.trainingItem', 'tierRequirements.professionalCertification', 'product', 'vendor']);

        $requirements = $tier->tierRequirements
            ->map(fn (VendorTierRequirement $r) => $this->resolve($r, $tier))
            ->values()
            ->all();

        $commercial = $this->commercial($tier);

        $gaps = collect($requirements)
            ->filter(fn (array $r) => $r['state'] === 'short' || $r['state'] === 'unmeasurable')
            ->map(fn (array $r) => $r['state'] === 'unmeasurable'
                ? "{$r['label']} cannot be counted — it is not linked to a training item"
                : "{$r['label']}: {$r['held']} of {$r['required']} people hold it")
            ->values();

        if ($commercial && $commercial['state'] === 'short') {
            $gaps->push(
                "Seat commitment: {$commercial['seats_activated']} of {$commercial['seats_required']} seats activated"
            );
        }

        return [
            'tier'         => $this->tierArray($tier),
            'requirements' => $requirements,
            'commercial'   => $commercial,
            'status'       => $this->status($requirements, $commercial),
            'gaps'         => $gaps->all(),
        ];
    }

    /** Every tier this vendor holds or has held, newest programme first. */
    public function forVendor(VendorPartner $vendor): array
    {
        return $vendor->partnershipTiers()
            ->with(['tierRequirements.trainingItem', 'tierRequirements.professionalCertification', 'product'])
            ->orderByDesc('tier_order')
            ->get()
            ->map(fn (VendorPartnershipTier $tier) => $this->forTier($tier))
            ->all();
    }

    /**
     * How many of our people hold this, and whether that is enough.
     */
    private function resolve(VendorTierRequirement $requirement, VendorPartnershipTier $tier): array
    {
        $itemId   = $requirement->countableItemId();
        $required = max(1, (int) $requirement->required_headcount);

        $base = [
            'id'          => $requirement->id,
            'type'        => $requirement->requirement_type,
            'label'       => $requirement->label(),
            'required'    => $required,
            'notes'       => $requirement->notes,
        ];

        /*
         * A vendor certification that maps to no training item is a name we
         * were given, not something anybody here can be recorded as holding.
         * Said out loud rather than counted as zero, which would read as a
         * failure the team could fix by studying.
         */
        if ($itemId === null) {
            return [...$base, 'held' => null, 'expiring_soon' => 0, 'state' => 'unmeasurable'];
        }

        $organizationId = $tier->organization_id ?? $tier->vendor?->organization_id;

        $holders = EmployeeTrainingRecord::query()
            ->where('training_item_id', $itemId)
            ->whereHas('employee', fn ($q) => $q->where('organization_id', $organizationId))
            ->where('status', 'completed')
            // An expired certificate is not held. The record stays as history;
            // it stops counting towards a tier the day it lapses, which is the
            // whole reason expiry is tracked.
            ->where(fn ($q) => $q->whereNull('certificate_expiry_date')
                ->whereDate('certificate_expiry_date', '>=', now(), 'or'))
            ->count();

        $expiring = EmployeeTrainingRecord::query()
            ->where('training_item_id', $itemId)
            ->whereHas('employee', fn ($q) => $q->where('organization_id', $organizationId))
            ->where('status', 'completed')
            ->whereNotNull('certificate_expiry_date')
            ->whereBetween('certificate_expiry_date', [now(), now()->addDays(self::EXPIRING_SOON_DAYS)])
            ->count();

        return [
            ...$base,
            'held'          => $holders,
            'expiring_soon' => $expiring,
            'state'         => match (true) {
                $holders < $required                   => 'short',
                // Enough today, but not for long — the warning that lets
                // somebody book an exam before the tier is actually lost.
                $expiring > 0 && ($holders - $expiring) < $required => 'at_risk',
                default                                => 'met',
            },
        ];
    }

    /**
     * The commercial half: seats activated against seats committed.
     *
     * Null when the tier carries no revenue commitment — most product tiers
     * are earned on people alone, and inventing a zero target for those would
     * report every one of them as failing.
     */
    private function commercial(VendorPartnershipTier $tier): ?array
    {
        if (! $tier->hasMonetaryRequirement()) {
            return null;
        }

        $required  = (int) ($tier->seats_required ?? 0);
        $activated = (int) ($tier->seats_activated ?? 0);

        return [
            'revenue_target'   => $tier->revenue_target,
            'currency'         => $tier->revenue_currency,
            'seats_required'   => $required,
            'seats_activated'  => $activated,
            'state'            => match (true) {
                // A money target with no seat count behind it cannot be
                // checked; saying so beats reporting a confident pass.
                $required === 0            => 'unmeasurable',
                $activated >= $required    => 'met',
                default                    => 'short',
            },
        ];
    }

    /**
     * The tier's own verdict.
     *
     * `at_risk` exists so a manager hears about an expiring certification
     * while there is still time to sit the exam — the difference between a
     * warning and a post-mortem.
     */
    private function status(array $requirements, ?array $commercial): string
    {
        $states = collect($requirements)->pluck('state');

        if ($commercial) {
            $states->push($commercial['state']);
        }

        if ($states->isEmpty()) {
            // Nothing is required of it, so nothing is missing. A tier with no
            // requirements is a label, and this says so honestly rather than
            // awarding it a compliant badge it did not earn.
            return 'unmeasured';
        }

        return match (true) {
            $states->contains('short')        => 'non_compliant',
            $states->contains('at_risk')      => 'at_risk',
            $states->contains('unmeasurable') => 'unmeasured',
            default                           => 'compliant',
        };
    }

    private function tierArray(VendorPartnershipTier $tier): array
    {
        return [
            'id'          => $tier->id,
            'tier_name'   => $tier->tier_name,
            'tier_order'  => $tier->tier_order,
            'scope'       => $tier->scope ?? 'general',
            'product'     => $tier->product ? [
                'id' => $tier->product->id, 'name' => $tier->product->name,
            ] : null,
            'is_current'  => $tier->isInForce(),
            'valid_from'  => $tier->valid_from?->toDateString(),
            'valid_until' => $tier->valid_until?->toDateString(),
        ];
    }
}
