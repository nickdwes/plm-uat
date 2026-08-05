<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stops transport being priced by grade.
 *
 * Transport entitlement is mileage- and cost-driven. A flat daily ceiling for
 * every grade cannot tell a 40 km errand from a 600 km round trip, and now
 * that transport is computed from distance, fuel and mode, leaving the old
 * allowance in place would mean two answers to the same question — a leg
 * costed at 14,400 sitting beside a ceiling of 3,000 that nobody can satisfy.
 *
 * FLIGHTS GO TOO, for the same reason. The requirement lists flight tickets
 * and visa fees as transport costs captured per journey, so a grade-priced
 * flight allowance would double-count exactly as transport would. If flights
 * should remain a grade allowance, this half is the piece to reverse.
 *
 * Retired rather than deleted:
 *
 *   Rates are closed with an effective_to, not removed, because a request
 *   costed against one must stay explicable. That is the same discipline the
 *   settings screen enforces on every other rate change.
 *
 *   Categories are deactivated, not dropped. Expense lines hold a foreign key
 *   to them, and a deleted category would leave historical claims showing no
 *   category at all.
 *
 * What remains grade-priced is what should be: per diem, accommodation and
 * incidentals. A unit head does get a better hotel; they do not get a longer
 * road.
 */
return new class extends Migration
{
    /** Categories now answered by a transport leg rather than an allowance. */
    private const SUPERSEDED = ['Transport', 'Flights'];

    public function up(): void
    {
        $categories = DB::table('travel_expense_categories')
            ->whereIn('name', self::SUPERSEDED)
            ->pluck('id');

        if ($categories->isEmpty()) {
            return;
        }

        // Close any rate still in force, dated yesterday so nothing is left
        // ambiguous for today.
        DB::table('travel_allowance_rates')
            ->whereIn('travel_expense_category_id', $categories)
            ->whereNull('effective_to')
            ->update([
                'effective_to' => now()->subDay()->toDateString(),
                'updated_at'   => now(),
            ]);

        DB::table('travel_expense_categories')
            ->whereIn('id', $categories)
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    /**
     * Reactivates the categories, but does not resurrect the rates.
     *
     * Reopening a closed rate would silently restore a ceiling against which
     * requests may since have been approved under the new model. Whoever
     * reverses this should set the rates deliberately.
     */
    public function down(): void
    {
        DB::table('travel_expense_categories')
            ->whereIn('name', self::SUPERSEDED)
            ->update(['is_active' => true, 'updated_at' => now()]);
    }
};
