<?php

namespace App\Services\Vendor;

use App\Models\Organization;
use App\Models\Training\VendorPartner;
use App\Models\Vendor\StakeholderRole;
use App\Models\Vendor\VendorContact;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Vendor Module — Registration & Lifecycle Service
 *
 * Handles vendor CRUD and onboarding status transitions
 * (approve / reject / suspend / reactivate).
 */
class VendorService
{
    // ─────────────────────────────────────────────────────────────────────────
    // List / Show
    // ─────────────────────────────────────────────────────────────────────────

    public function list(
        int    $orgId,
        array  $filters  = [],
        int    $perPage  = 25
    ): LengthAwarePaginator {
        $q = VendorPartner::where('organization_id', $orgId)
            ->with('stakeholderRoles')
            ->orderBy('name');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $q->where(function ($w) use ($term) {
                $w->where('name', 'ilike', $term)
                  ->orWhere('vendor_number', 'ilike', $term)
                  ->orWhere('contact_email', 'ilike', $term)
                  ->orWhere('registration_number', 'ilike', $term)
                  ->orWhere('tax_id', 'ilike', $term);
            });
        }

        if (!empty($filters['vendor_type'])) {
            $q->where('vendor_type', $filters['vendor_type']);
        }

        if (!empty($filters['onboarding_status'])) {
            $q->where('onboarding_status', $filters['onboarding_status']);
        }

        if (isset($filters['is_active'])) {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($filters['is_oem_vendor'])) {
            $q->where('is_oem_vendor', filter_var($filters['is_oem_vendor'], FILTER_VALIDATE_BOOLEAN));
        }

        /*
         * Filtering by role asks the roles table, and only about roles in
         * force — a party that stopped being a distributor last year should
         * not answer a search for distributors. `vendor_type` above still
         * works for callers that have not moved over.
         */
        if (! empty($filters['role'])) {
            $roles = is_array($filters['role']) ? $filters['role'] : explode(',', (string) $filters['role']);
            $roles = array_values(array_filter(array_map('trim', $roles)));

            if ($roles !== []) {
                $q->whereHas('stakeholderRoles', fn ($r) => $r->inForce()->whereIn('role', $roles));
            }
        }

        if (! empty($filters['party_class'])) {
            $q->where('party_class', $filters['party_class']);
        }

        return $q->paginate($perPage);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Create / Update / Delete
    // ─────────────────────────────────────────────────────────────────────────

    public function create(int $orgId, array $data): VendorPartner
    {
        $roles = $data['roles'] ?? [];
        unset($data['roles']);

        $data['organization_id'] = $orgId;
        $data['vendor_number']   = $this->generateVendorNumber($orgId);

        return DB::transaction(function () use ($orgId, $data, $roles) {
            $vendor = VendorPartner::create($data);

            /*
             * Roles are the classification now. The legacy `vendor_type` and
             * `is_oem_vendor` columns are still written so the consumers that
             * filter on them (procurement, projects, assets — 26 foreign keys
             * in all) keep working; they are derived FROM the roles, never
             * the other way round.
             */
            foreach (array_unique($roles) as $role) {
                $this->addRole($vendor, $role, null, null, $data['created_by'] ?? null);
            }

            return $vendor->fresh('stakeholderRoles');
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Classification
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Start a role, from a date (today by default).
     *
     * Re-adding a role already in force is a no-op rather than an error: the
     * caller's intent ("they are a distributor") is already true, and a
     * duplicate row would make the end-date ambiguous.
     */
    public function addRole(
        VendorPartner $vendor,
        string $role,
        ?string $from = null,
        ?string $notes = null,
        ?int $actorId = null,
    ): StakeholderRole {
        $from ??= now()->toDateString();

        $existing = $vendor->stakeholderRoles()->inForce()->where('role', $role)->first();

        if ($existing) {
            return $existing;
        }

        $created = StakeholderRole::create([
            'organization_id'   => $vendor->organization_id,
            'vendor_partner_id' => $vendor->id,
            'role'              => $role,
            'valid_from'        => $from,
            'notes'             => $notes,
            'created_by'        => $actorId,
        ]);

        $this->syncLegacyClassification($vendor);

        return $created;
    }

    /**
     * End a role. The row stays — an ended relationship is history, not a
     * mistake, and deleting it would silently rewrite what we were.
     */
    public function endRole(StakeholderRole $role, ?string $on = null): StakeholderRole
    {
        $role->update(['valid_until' => $on ?? now()->toDateString()]);

        $this->syncLegacyClassification($role->vendor);

        return $role->fresh();
    }

    /**
     * Keep the deprecated columns honest.
     *
     * They are read by procurement, projects and asset code that has not
     * moved onto roles yet. Deriving them here means those readers never see
     * a classification that disagrees with the roles table.
     */
    private function syncLegacyClassification(VendorPartner $vendor): void
    {
        $vendor->load('stakeholderRoles');
        $active = $vendor->activeRoles()->pluck('role')->all();

        $toLegacy = [
            'supplier'              => 'supplier',
            'service_provider'      => 'service_provider',
            'contractor_company'    => 'contractor',
            'contractor_individual' => 'contractor',
            'subcontractor'         => 'contractor',
            'consultant'            => 'consultant',
            'distributor'           => 'distributor',
            'channel_partner'       => 'partner',
        ];

        // The first mapped role wins the single legacy slot; OEM has its own
        // boolean and never competes for it.
        $legacy = null;
        foreach ($active as $role) {
            if (isset($toLegacy[$role])) {
                $legacy = $toLegacy[$role];
                break;
            }
        }

        $vendor->forceFill([
            'vendor_type'   => $legacy ?? $vendor->vendor_type,
            'is_oem_vendor' => in_array('oem', $active, true),
        ])->save();
    }

    public function update(VendorPartner $vendor, array $data): VendorPartner
    {
        $vendor->update($data);
        return $vendor->fresh();
    }

    public function delete(VendorPartner $vendor): void
    {
        $vendor->delete();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Onboarding status transitions
    // ─────────────────────────────────────────────────────────────────────────

    public function submitForReview(VendorPartner $vendor): VendorPartner
    {
        $vendor->update(['onboarding_status' => 'pending_review']);
        return $vendor->fresh();
    }

    public function approve(VendorPartner $vendor, int $byUserId): VendorPartner
    {
        $vendor->update([
            'onboarding_status' => 'approved',
            'is_active'         => true,
            'approved_at'       => now(),
            'approved_by'       => $byUserId,
            'rejection_reason'  => null,
        ]);
        return $vendor->fresh();
    }

    public function reject(VendorPartner $vendor, string $reason): VendorPartner
    {
        $vendor->update([
            'onboarding_status' => 'rejected',
            'is_active'         => false,
            'rejection_reason'  => $reason,
        ]);
        return $vendor->fresh();
    }

    public function suspend(VendorPartner $vendor, string $reason): VendorPartner
    {
        $vendor->update([
            'onboarding_status' => 'suspended',
            'is_active'         => false,
            'rejection_reason'  => $reason,
        ]);
        return $vendor->fresh();
    }

    public function reactivate(VendorPartner $vendor): VendorPartner
    {
        $vendor->update([
            'onboarding_status' => 'approved',
            'is_active'         => true,
            'rejection_reason'  => null,
        ]);
        return $vendor->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Summary counts for dashboard metrics
    // ─────────────────────────────────────────────────────────────────────────

    public function metrics(int $orgId): array
    {
        $rows = VendorPartner::where('organization_id', $orgId)
            ->selectRaw('onboarding_status, count(*) as cnt')
            ->groupBy('onboarding_status')
            ->pluck('cnt', 'onboarding_status')
            ->toArray();

        return [
            'total'          => array_sum($rows),
            'draft'          => (int) ($rows['draft']          ?? 0),
            'pending_review' => (int) ($rows['pending_review'] ?? 0),
            'approved'       => (int) ($rows['approved']       ?? 0),
            'rejected'       => (int) ($rows['rejected']       ?? 0),
            'suspended'      => (int) ($rows['suspended']      ?? 0),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────────────────
    // Contacts
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Add a person at this vendor.
     *
     * Same rule the customer side follows: the first contact is primary
     * whether or not anyone said so — there is nobody else to call — and
     * `is_primary` is derived from the category so the two can never
     * disagree about who to ring.
     */
    public function addContact(VendorPartner $vendor, array $data, ?int $actorId = null): VendorContact
    {
        return DB::transaction(function () use ($vendor, $data, $actorId) {
            $first    = ! $vendor->contacts()->exists();
            $category = $first ? 'primary' : ($data['category'] ?? 'other');

            if ($category === 'primary' && ! $first) {
                $this->standDownPrimary($vendor->id);
            }

            $contact = $vendor->contacts()->create(array_merge($data, [
                'category'        => $category,
                'is_primary'      => $category === 'primary',
                'organization_id' => $vendor->organization_id,
                'created_by'      => $actorId,
            ]));

            $this->syncDenormalisedContact($vendor);

            return $contact;
        });
    }

    public function updateContact(VendorContact $contact, array $data): VendorContact
    {
        return DB::transaction(function () use ($contact, $data) {
            if (array_key_exists('category', $data)) {
                if ($data['category'] === 'primary') {
                    $this->standDownPrimary($contact->vendor_partner_id, $contact->getKey());
                }

                $data['is_primary'] = $data['category'] === 'primary';
            }

            $contact->update($data);
            $this->syncDenormalisedContact($contact->vendor);

            return $contact->fresh();
        });
    }

    public function deleteContact(VendorContact $contact): void
    {
        $vendor = $contact->vendor;
        $contact->delete();
        $this->syncDenormalisedContact($vendor);
    }

    private function standDownPrimary(int $vendorId, ?int $exceptId = null): void
    {
        VendorContact::where('vendor_partner_id', $vendorId)
            ->where('category', 'primary')
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->update(['category' => 'secondary', 'is_primary' => false]);
    }

    /**
     * Keep the row's own contact columns pointing at the primary contact.
     *
     * `primary_contact_name` / `contact_email` / `contact_phone` are read all
     * over the vendor list and by the procurement screens. Deriving them from
     * the contact table means the summary a buyer sees can never name someone
     * who left.
     */
    private function syncDenormalisedContact(VendorPartner $vendor): void
    {
        $primary = $vendor->contacts()
            ->where('is_active', true)
            ->orderByDesc('is_primary')
            ->first();

        $vendor->forceFill([
            'primary_contact_name'        => $primary?->full_name,
            'primary_contact_designation' => $primary?->designation,
            'contact_email'               => $primary?->email ?? $vendor->contact_email,
            'contact_phone'               => $primary?->phone ?? $vendor->contact_phone,
        ])->save();
    }

    private function generateVendorNumber(int $orgId): string
    {
        $prefix = $this->orgPrefix($orgId);

        // Find the highest sequential number already assigned for this prefix
        $max = VendorPartner::where('organization_id', $orgId)
            ->where('vendor_number', 'like', $prefix . '-V%')
            ->selectRaw("MAX(CAST(NULLIF(REGEXP_REPLACE(vendor_number, '[^0-9]', '', 'g'), '') AS INTEGER)) as max_seq")
            ->value('max_seq');

        $next = ($max ?? 0) + 1;
        return $prefix . '-V' . str_pad($next, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Derive a short uppercase prefix from the organisation name.
     *
     * Strategy: take the first letter of each significant word (up to 4).
     * e.g. "Konvergenz Network Solutions" → "KNS"
     *      "Teedu Academy"               → "TA"
     *      "Bitwyse"                     → "BIT"
     */
    public function orgPrefix(int $orgId): string
    {
        $org = Organization::find($orgId);
        if (!$org) {
            return 'VND';
        }

        // Strip non-alpha, split on whitespace
        $skipWords = ['ltd', 'inc', 'co', 'corp', 'llc', 'plc', 'limited', 'company', 'group', 'and', 'the', 'of'];
        $words = array_values(array_filter(
            preg_split('/\s+/', preg_replace('/[^a-z\s]/i', '', $org->name)),
            fn($w) => strlen($w) > 0 && !in_array(strtolower($w), $skipWords)
        ));

        if (count($words) >= 2) {
            return strtoupper(implode('', array_map(
                fn($w) => $w[0],
                array_slice($words, 0, 4)
            )));
        }

        // Single word: use first 3 characters
        return strtoupper(substr($org->slug, 0, 3));
    }
}
