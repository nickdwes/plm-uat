<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a functional unit a head.
 *
 * Travel's second approval stage is the chief manager of the function.
 * `departments` already names a lead; `org_functional_units` did not, so that
 * stage had nobody to route to — a request would reach it and stop.
 *
 * Note this does not fix the other half: only 3 of 17 departments currently
 * set `functional_unit_id`, so for the other 14 the function cannot be
 * resolved from the requester at all. That is data rather than schema, and it
 * has to be filled before Travel goes live or those requests stall with no
 * approver and no error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('org_functional_units', function (Blueprint $table) {
            $table->foreignId('lead_employee_id')->nullable()->after('description')
                  ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('org_functional_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_employee_id');
        });
    }
};
