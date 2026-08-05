<?php

namespace App\Support\Account;

use Illuminate\Support\Facades\DB;

/**
 * The types and sectors a new organisation starts with.
 *
 * Ported from Plmv2's seed migrations. Held here rather than in the migration
 * that first applied them, because two paths need the same list: the migration
 * that back-fills organisations created before this existed, and the
 * provisioning of every organisation created after.
 *
 * Without the second path a new tenant would open the customer form to empty
 * dropdowns with nothing to explain them — the same failure the travel wizard
 * had, arriving by a different route.
 */
final class DefaultReferenceData
{
    public const TYPES = ['Prospect', 'Client', 'Partner', 'Supplier'];

    public const SECTORS = [
        'BFSI',            // banking, financial services, insurance
        'Education',
        'Energy',
        'Healthcare',
        'Manufacturing',
        'Non-government Organization (NGO)',
        'Public Sector',
        'Services',
        'Technology',
        'Transport',
    ];

    /**
     * Idempotent: insertOrIgnore against the (organization_id, name) uniques,
     * so running it twice never collides with a list somebody has edited.
     */
    public static function seedFor(int $organizationId): void
    {
        $now = now();

        foreach (self::TYPES as $name) {
            DB::table('account_types')->insertOrIgnore([
                'organization_id' => $organizationId, 'name' => $name,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (self::SECTORS as $name) {
            DB::table('account_sectors')->insertOrIgnore([
                'organization_id' => $organizationId, 'name' => $name,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
