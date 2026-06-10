<?php

namespace App\Services\Vendor;

use App\Models\Organization;
use App\Models\Training\VendorPartner;
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

        return $q->paginate($perPage);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Create / Update / Delete
    // ─────────────────────────────────────────────────────────────────────────

    public function create(int $orgId, array $data): VendorPartner
    {
        $data['organization_id'] = $orgId;
        $data['vendor_number']   = $this->generateVendorNumber($orgId);

        return VendorPartner::create($data);
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
