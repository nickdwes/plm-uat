<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The taxable figure the tax was actually charged on.
 *
 * It was never stored, so the P9 report recomputed it as `gross − NSSF` — the
 * old formula. The moment the engine started allowing SHIF and the housing levy
 * before PAYE, the P9 began reporting a taxable income that no PAYE figure on
 * it had been derived from: two numbers on one statutory return disagreeing
 * about the same month.
 *
 * Recomputing a figure somewhere else is how it drifts. It is written once,
 * when the run is computed, and read everywhere after.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->decimal('taxable_income', 14, 2)->nullable()->after('gross_pay');
            // What was allowed against income before tax, so a payslip can show
            // its working rather than asserting a number.
            $table->decimal('allowable_deductions', 14, 2)->nullable()->after('taxable_income');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', fn (Blueprint $t) => $t->dropColumn([
            'taxable_income', 'allowable_deductions',
        ]));
    }
};
