<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the travel schema in line with the request journey.
 *
 * THE RATE CARD BECOMES A CEILING, NOT A CALCULATOR.
 *
 * The first cut computed a request's value from the allowance rates. The
 * journey does the opposite: the traveller enters what the trip will actually
 * cost — a real flight has a real price — and the rate is the limit that
 * estimate is checked against, so the request can be submitted while showing
 * "exceeds policy" for an approver to weigh.
 *
 * That is the honest model. Auto-costing produces a number nobody believes and
 * an approval process that quietly happens over email instead, because the
 * figure on the request bears no relation to the fare.
 *
 * `policy_limit` is snapshotted next to each line for the same reason
 * `rate_applied` was: changing the rate card afterwards must not silently
 * re-judge a decision an approver already made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_requests', function (Blueprint $table) {
            $table->string('priority')->nullable()->after('travel_mode');       // low | medium | high
            $table->boolean('accommodation_needed')->default(false)->after('priority');
            $table->boolean('flexible_days')->default(false)->after('accommodation_needed');

            // An advance is a request for money before the trip, so the reason
            // is part of the request rather than a note somewhere.
            $table->text('advance_justification')->nullable()->after('advance_required');

            /*
             * The task this trip is against.
             *
             * A reference rather than a foreign key: Tasks is a later phase, and
             * a constraint against a table that does not exist yet cannot be
             * added. Repointed to a real key when Tasks lands.
             */
            $table->string('linked_task_ref')->nullable()->after('account_id');
        });

        Schema::table('travel_request_items', function (Blueprint $table) {
            $table->string('description')->nullable()->after('travel_expense_category_id');

            // What the policy allowed when this line was entered, and whether
            // the estimate went over it. Both are recorded rather than
            // recalculated, so an approver's decision stays explicable after
            // the rate card moves.
            $table->decimal('policy_limit', 14, 2)->nullable()->after('rate_applied');
            $table->boolean('exceeds_policy')->default(false)->after('policy_limit');

            // Filled after the trip, alongside the estimate. Not a
            // reconciliation workflow — the legacy one held zero rows across
            // 102 requests — just the column that makes over-spend visible.
            $table->decimal('actual_cost', 14, 2)->nullable()->after('amount_approved');
        });

        // The estimate is now the traveller's, so a line can exist without a
        // rate behind it: a category the organisation has not priced still
        // needs to be claimable, flagged rather than blocked.
        DB::statement('ALTER TABLE travel_request_items ALTER COLUMN rate_applied DROP NOT NULL');
    }

    public function down(): void
    {
        Schema::table('travel_requests', function (Blueprint $table) {
            $table->dropColumn(['priority', 'accommodation_needed', 'flexible_days',
                                'advance_justification', 'linked_task_ref']);
        });

        Schema::table('travel_request_items', function (Blueprint $table) {
            $table->dropColumn(['description', 'policy_limit', 'exceeds_policy', 'actual_cost']);
        });
    }
};
