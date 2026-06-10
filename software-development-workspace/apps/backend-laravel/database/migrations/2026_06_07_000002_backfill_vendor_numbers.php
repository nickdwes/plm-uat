<?php

use App\Models\Organization;
use App\Models\Training\VendorPartner;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill vendor_number for existing rows that have a null value.
 * Generates org-prefixed numbers like "KNS-V01", "KNS-V02", etc.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Skip when run in testing with no data
        if (!class_exists(Organization::class)) {
            return;
        }

        $vendorsByOrg = VendorPartner::whereNull('vendor_number')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('organization_id');

        foreach ($vendorsByOrg as $orgId => $vendors) {
            $prefix   = $this->orgPrefix((int) $orgId);
            $existing = VendorPartner::where('organization_id', $orgId)
                ->where('vendor_number', 'like', $prefix . '-V%')
                ->whereNotNull('vendor_number')
                ->selectRaw("MAX(CAST(NULLIF(REGEXP_REPLACE(vendor_number, '[^0-9]', '', 'g'), '') AS INTEGER)) as max_seq")
                ->value('max_seq') ?? 0;

            foreach ($vendors as $vendor) {
                $existing++;
                $vendor->vendor_number = $prefix . '-V' . str_pad($existing, 2, '0', STR_PAD_LEFT);
                $vendor->saveQuietly();
            }
        }
    }

    public function down(): void
    {
        // Irreversible data fill — no rollback needed
    }

    private function orgPrefix(int $orgId): string
    {
        $org = Organization::find($orgId);
        if (!$org) {
            return 'VND';
        }

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

        return strtoupper(substr($org->slug, 0, 3));
    }
};
