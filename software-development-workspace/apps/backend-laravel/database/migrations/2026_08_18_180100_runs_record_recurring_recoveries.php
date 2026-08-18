<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What standing orders took, kept beside the total they are part of.
 *
 * Derivable by summing the run's recurring lines, and stored anyway: the
 * payslip, the period summary and the bank file all want it, and three places
 * each doing their own sum is how three answers appear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->decimal('recurring_deductions', 14, 2)->default(0)->after('total_deductions');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn('recurring_deductions');
        });
    }
};
