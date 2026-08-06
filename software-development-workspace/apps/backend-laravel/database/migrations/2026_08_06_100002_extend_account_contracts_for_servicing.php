<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the contract screens need beyond recording that one exists.
 *
 * The first cut was Plmv2's shape — name, number, value, dates. The legacy
 * system carried more and used it: 55 contracts, all account-linked, with an
 * SLA on 26 of them, a billing cycle, an owning business unit and a project.
 * A contract you can file but not service is only half the record.
 *
 * `project_reference` is deliberately a string. Projects do not exist here
 * yet, and a foreign key to nothing is worse than no column — this holds what
 * the contract says it is for, and becomes an FK when Projects lands rather
 * than being invented twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_contracts', function (Blueprint $table) {
            // The reference the screens show (#TJ819), distinct from
            // contract_number, which is the customer's own paperwork number.
            $table->string('reference', 32)->nullable()->after('id');

            $table->string('billing_cycle', 32)->nullable()->after('contract_value');

            // Which part of the business carries it. The legacy held this on
            // the SLA as a business-unit id; there is no BU concept here yet,
            // so it is recorded as written and can be resolved later.
            $table->string('owner')->nullable()->after('billing_cycle');

            $table->string('project_reference')->nullable()->after('owner');

            $table->timestamp('completed_at')->nullable()->after('status');

            $table->unique(['organization_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::table('account_contracts', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'reference']);
            $table->dropColumn(['reference', 'billing_cycle', 'owner', 'project_reference', 'completed_at']);
        });
    }
};
