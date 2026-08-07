<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of the delivery we take, and how much goes to the OEM.
 *
 * Replaces the legacy's `projects.ps` and `projects.ps_ratio`, which were a
 * boolean unanswered on 61% of rows and a free-text ratio blank on 63% — and
 * where, of the 26 values that did exist, `100` gave no way to tell whether it
 * meant all ours or all theirs. `5` and `95` are the same decision written from
 * opposite ends. One row read "Nairobi".
 *
 * The ambiguity is why it was never trusted, so both sides are named here and
 * the ratio is derived rather than typed.
 *
 * Days rather than a percentage, for three reasons:
 *
 *   A percentage cannot be costed. OEM professional services are bought at the
 *   vendor's rate; our own days are internal capacity. Those are different
 *   money, and the allocation is a cost decision.
 *
 *   A percentage cannot be staffed. "We take 80%" tells a resource manager
 *   nothing; "we take 34 days" does.
 *
 *   A percentage cannot be checked. 90% of the legacy's 9,055 tasks carry a
 *   work-effort estimate and 91% an end date, so delivered own-effort is
 *   already measurable — planned against actual becomes a report.
 *
 * On the estimate rather than the opportunity, because the split is a property
 * of a scoped solution: a deal delivered in phases can allocate differently per
 * phase, which the legacy's single project-level field could never express.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunity_estimates', function (Blueprint $table) {
            // Which OEM's professional services. Links to the partner module
            // that already exists rather than a free-text vendor name — the
            // legacy referenced OEMs by a stable id and reused them, so this
            // is not a new concept.
            $table->foreignId('vendor_partner_id')->nullable()->after('opportunity_id')
                ->constrained('vendor_partners')->nullOnDelete();

            // Both sides named, so the split has a direction.
            $table->decimal('delivery_days_own', 8, 1)->nullable()->after('profit');
            $table->decimal('delivery_days_oem', 8, 1)->nullable()->after('delivery_days_own');

            // What the OEM charges us for their share. Commercial, and gated
            // like every other figure.
            $table->decimal('oem_ps_cost', 15, 2)->nullable()->after('delivery_days_oem');
        });

        /*
         * The same four on the project, carried at kickoff.
         *
         * Kept separate rather than read through the estimate: the estimate is
         * what was planned and the project is what happened, and the gap
         * between them is the point. Overwriting one with the other would
         * destroy the only measurement worth having.
         */
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('vendor_partner_id')->nullable()->after('opportunity_id')
                ->constrained('vendor_partners')->nullOnDelete();

            $table->decimal('delivery_days_own', 8, 1)->nullable()->after('contract_value');
            $table->decimal('delivery_days_oem', 8, 1)->nullable()->after('delivery_days_own');
            $table->decimal('oem_ps_cost', 15, 2)->nullable()->after('delivery_days_oem');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_partner_id');
            $table->dropColumn(['delivery_days_own', 'delivery_days_oem', 'oem_ps_cost']);
        });

        Schema::table('opportunity_estimates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_partner_id');
            $table->dropColumn(['delivery_days_own', 'delivery_days_oem', 'oem_ps_cost']);
        });
    }
};
