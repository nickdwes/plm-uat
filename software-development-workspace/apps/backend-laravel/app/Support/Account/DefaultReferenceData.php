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
     * The sales process a deal moves through, in order.
     *
     * Konvergenz's own fourteen, carried over because all 130 legacy
     * opportunities sat in one of them. Win and Lost carry an outcome, which
     * is what makes them different from the twelve that precede them: the
     * phase is the only place that decides whether a deal is closed.
     */
    public const OPPORTUNITY_PHASES = [
        ['Opportunity Processing', null],
        ['Scope/Site Survey', null],
        ['Estimate', null],
        ['Opportunity Qualification', null],
        ['Specifications', null],
        ['Awaiting Budget Confirmation', null],
        ['Opportunity Commit', null],
        ['Awaiting BID Release', null],
        ['Bidding', null],
        ['Negotiations', null],
        ['Win', 'won'],
        ['Lost', 'lost'],
        ['Contracting', null],
        ['Project Kick-Off', null],
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

        foreach (self::OPPORTUNITY_PHASES as $i => [$name, $outcome]) {
            DB::table('opportunity_phases')->insertOrIgnore([
                'organization_id' => $organizationId, 'name' => $name,
                'position' => $i + 1, 'outcome' => $outcome,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
