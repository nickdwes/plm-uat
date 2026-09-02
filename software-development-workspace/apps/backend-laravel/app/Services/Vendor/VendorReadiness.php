<?php

namespace App\Services\Vendor;

use App\Models\Training\VendorPartner;
use App\Models\Vendor\StakeholderRole;
use App\Models\Vendor\StakeholderRoleField;
use App\Models\Vendor\StakeholderRoleRequirement;
use App\Models\Vendor\VendorDocument;
use Illuminate\Support\Collection;

/**
 * Is this supplier fit to trade with?
 *
 * Answered from the roles it currently holds and the documents it has
 * produced — not from a status column somebody set by hand. A distributor
 * whose OEM authorisation lapsed last month is not authorised, whatever the
 * record says, and the point of computing this is that nobody has to remember
 * to change anything when a date passes.
 *
 * Deliberately mirrors `VendorComplianceService::status()`, which answers the
 * training-headcount question the same way, so the two compliance ideas on
 * this screen read alike.
 */
class VendorReadiness
{
    /**
     * Requirements for the roles in force, deduplicated.
     *
     * A party that is both a distributor and a contractor owes the union of
     * both lists: it is one company, and the tax certificate it filed
     * satisfies both. Only the strongest demand survives a clash — something
     * mandatory for one role is not made optional by another.
     *
     * @return Collection<string, array{document_key:string,label:string,requirement:string,help:?string,roles:string[]}>
     */
    public function requirementsFor(VendorPartner $vendor): Collection
    {
        $roles = $vendor->stakeholderRoles
            ->filter(fn (StakeholderRole $r) => $r->isInForce())
            ->pluck('role')
            ->unique()
            ->values();

        if ($roles->isEmpty()) {
            return collect();
        }

        // Through the model's own resolver, so an organisation that has never
        // customised its rulebook still gets the baseline rather than an empty
        // checklist that silently blocks nothing.
        return StakeholderRoleRequirement::forOrganization($vendor->organization_id)
            ->whereIn('role', $roles->all())
            ->groupBy('document_key')
            ->map(function (Collection $forKey) {
                $mandatory = $forKey->first(fn ($r) => $r->isMandatory());
                $chosen    = $mandatory ?? $forKey->first();

                return [
                    'document_key' => $chosen->document_key,
                    'label'        => $chosen->label,
                    'requirement'  => $mandatory ? 'mandatory' : 'optional',
                    'help'         => $chosen->help,
                    // Which roles are asking, so a person can see why.
                    'roles'        => $forKey->pluck('role')->unique()->values()->all(),
                ];
            });
    }

    /**
     * Every requirement with what satisfies it, if anything.
     *
     * @return array<int, array>
     */
    public function checklist(VendorPartner $vendor): array
    {
        // Reuse the loaded relation when the caller eager-loaded it; only
        // reach for the database when nobody did.
        $documents = ($vendor->relationLoaded('documents')
            ? $vendor->documents
            : $vendor->documents()->get())->groupBy('document_key');

        return $this->requirementsFor($vendor)->map(function (array $requirement) use ($documents) {
            /** @var VendorDocument|null $held */
            $held = ($documents[$requirement['document_key']] ?? collect())
                // The newest wins: a renewed certificate is filed alongside
                // the old one, and the old one must not keep the vendor blocked.
                ->sortByDesc(fn (VendorDocument $d) => $d->expiry_date?->timestamp ?? PHP_INT_MAX)
                ->first();

            $state = match (true) {
                $held === null      => 'missing',
                $held->isExpired()  => 'expired',
                default             => 'held',
            };

            return [
                ...$requirement,
                'state'    => $state,
                'document' => $held === null ? null : [
                    'id'           => $held->id,
                    'label'        => $held->label,
                    'expiry_date'  => $held->expiry_date?->toDateString(),
                    'is_perpetual' => $held->is_perpetual,
                    'days_left'    => $held->daysToExpiry(),
                ],
            ];
        })->values()->all();
    }

    /**
     * What stands between this party and being traded with.
     *
     * Only mandatory requirements block. An empty list is the activation
     * gate's green light; anything in it is a sentence a reviewer can act on
     * rather than a boolean they have to investigate.
     *
     * @return array<int, string>
     */
    public function blockers(VendorPartner $vendor): array
    {
        $documents = collect($this->checklist($vendor))
            ->filter(fn (array $item) => $item['requirement'] === 'mandatory' && $item['state'] !== 'held')
            ->map(fn (array $item) => $item['state'] === 'expired'
                ? "{$item['label']} expired on {$item['document']['expiry_date']}"
                : "{$item['label']} has not been provided");

        return $documents->concat($this->unansweredRoleFields($vendor))->values()->all();
    }

    /**
     * Questions the party's roles ask that nobody has answered.
     *
     * Beside the missing paperwork rather than in the save path: a draft is
     * unfinished by definition, and a form that refuses to save half-filled
     * loses the half somebody had. What must be complete is a SUBMISSION.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function unansweredRoleFields(VendorPartner $vendor): Collection
    {
        $roles = $vendor->stakeholderRoles
            ->filter(fn (StakeholderRole $r) => $r->isInForce())
            ->pluck('role')->unique()->values()->all();

        if ($roles === []) {
            return collect();
        }

        $answers = $vendor->role_details ?? [];

        return StakeholderRoleField::applyingTo(
            $vendor->organization_id, $roles, $vendor->party_class ?? 'organization',
        )
            ->filter(fn (StakeholderRoleField $f) => $f->is_required
                && ($answers[$f->key] ?? '') === '')
            ->map(fn (StakeholderRoleField $f) => "{$f->label} has not been answered")
            ->values();
    }

    public function isReady(VendorPartner $vendor): bool
    {
        return $this->blockers($vendor) === [];
    }
}
