<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The delivery record stops being about payslips only.
 *
 * A P9 goes to the same person, at the same address, locked with the same
 * password, and gets the same question a month later — "did she get it?". A
 * second table would answer that question in two places and drift.
 *
 * A P9 belongs to a tax year rather than a period, so the period becomes
 * nullable and the row carries what it is about. Existing rows are payslips,
 * which is why the type defaults rather than being backfilled by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_payslip_deliveries', function (Blueprint $table) {
            // payslip | p9
            $table->string('document_type', 16)->default('payslip')->after('id');
            $table->unsignedSmallInteger('tax_year')->nullable()->after('payroll_period_id');
        });

        // A P9 has no period, so the old unique key cannot hold it.
        Schema::table('payroll_payslip_deliveries', function (Blueprint $table) {
            $table->dropUnique('payslip_delivery_unique');
        });

        Schema::table('payroll_payslip_deliveries', function (Blueprint $table) {
            $table->foreignId('payroll_period_id')->nullable()->change();
            $table->unique(
                ['document_type', 'payroll_period_id', 'tax_year', 'employee_id', 'channel'],
                'payroll_delivery_unique',
            );
            $table->index(['document_type', 'tax_year', 'status'], 'payroll_delivery_year_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_payslip_deliveries', function (Blueprint $table) {
            $table->dropUnique('payroll_delivery_unique');
            $table->dropIndex('payroll_delivery_year_idx');
            $table->dropColumn(['document_type', 'tax_year']);
            $table->unique(['payroll_period_id', 'employee_id', 'channel'], 'payslip_delivery_unique');
        });
    }
};
