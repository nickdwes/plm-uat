<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The account record, harmonised against the agreed design.
 *
 * Three sources had to be reconciled: the legacy PLM (what Konvergenz actually
 * used for six years), Plmv2 (what was rebuilt), and the new screens. Where
 * they disagreed the decisions were:
 *
 *   Industry is the sector we already have. The design calls it Industry and
 *   shows "Technology", which is a row in account_sectors. Adding a column
 *   would give two answers to what business a customer is in.
 *
 *   Type and status stop overlapping. Legacy status was Active/Inactive and
 *   type was Key/Named/Developing; Plmv2 made type Prospect/Client/Partner/
 *   Supplier while status kept a `prospect` of its own, so a customer could be
 *   a Client of status prospect. The design settles it: type is the
 *   relationship, status is whether the record is live. `prospect` therefore
 *   leaves status and becomes `pending`, which is what the design shows.
 *
 *   The account summary — value, total deals, active deals, closed won — is
 *   not stored. Every figure is a fact about opportunities, and a stored copy
 *   is wrong from the first deal somebody edits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // The human reference the screens show (CL-10234). Assigned on
            // creation and never reused, so it can be quoted in an email and
            // still resolve years later.
            $table->string('reference', 32)->nullable()->after('name');

            $table->date('enrolled_on')->nullable()->after('status');

            /*
             * When we last spoke to them. Denormalised from the activity feed
             * because the list sorts and filters on it, and a per-row subquery
             * over activities is the thing that makes a customer list slow
             * once it is the size of a real book.
             */
            $table->timestamp('last_contact_at')->nullable()->after('enrolled_on');

            // Pipeline, as the Overview shows it.
            $table->string('lead_source')->nullable()->after('fund_source');
            $table->string('pipeline_stage')->nullable()->after('lead_source');

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'last_contact_at']);
        });

        // `prospect` is a type now, not a status. Done before the default
        // changes so no row is left holding a value the application no longer
        // recognises.
        DB::table('accounts')->where('status', 'prospect')->update(['status' => 'pending']);

        DB::statement("ALTER TABLE accounts ALTER COLUMN status SET DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE accounts ALTER COLUMN status SET DEFAULT 'prospect'");
        DB::table('accounts')->where('status', 'pending')->update(['status' => 'prospect']);

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'reference']);
            $table->dropIndex(['organization_id', 'last_contact_at']);
            $table->dropColumn(['reference', 'enrolled_on', 'last_contact_at', 'lead_source', 'pipeline_stage']);
        });
    }
};
