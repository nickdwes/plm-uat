<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An ad-hoc line has to say how it is taxed.
 *
 * The earning codes already carry is_taxable — a per diem is not taxed, a bonus
 * is — but a run item was only type, label and amount, so everything added to a
 * run went into taxable gross. Paying somebody a 20,000 per diem and charging
 * PAYE on it is not a rounding error; it is money taken from them that KRA
 * never asked for.
 *
 * The flag is stored on the item rather than read through to the code each
 * time. A code edited in March must not silently re-tax a run computed in
 * January: what was applied at the time is what the payslip said, and the
 * payslip is the document somebody has already filed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_run_items', function (Blueprint $table) {
            // Which code this came from, where it came from one at all. Nullable
            // because a one-off correction is a legitimate line with no code.
            $table->foreignId('payroll_earning_code_id')->nullable()->after('type')
                ->constrained('payroll_earning_codes')->nullOnDelete();
            $table->foreignId('payroll_deduction_code_id')->nullable()->after('payroll_earning_code_id')
                ->constrained('payroll_deduction_codes')->nullOnDelete();

            // Copied from the code when the line is written, then left alone.
            $table->boolean('is_taxable')->default(true)->after('amount');

            // Why, for the person who asks in six months.
            $table->string('note', 200)->nullable()->after('is_taxable');

            $table->foreignId('created_by')->nullable()->after('note')
                ->constrained('users')->nullOnDelete();

            $table->index(['payroll_run_id', 'type']);
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            /*
             * Paid, but outside the statutory base. Kept apart from gross_pay
             * rather than folded in: gross_pay is what the engine taxed and
             * what column A+B+C of the P9 reports, and a per diem inside it
             * would put a figure on a KRA return that the PAYE beside it
             * disagrees with.
             */
            $table->decimal('non_taxable_earnings', 14, 2)->default(0)->after('gross_pay');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_items', function (Blueprint $table) {
            $table->dropForeign(['payroll_earning_code_id']);
            $table->dropForeign(['payroll_deduction_code_id']);
            $table->dropForeign(['created_by']);
            $table->dropIndex(['payroll_run_id', 'type']);
            $table->dropColumn([
                'payroll_earning_code_id', 'payroll_deduction_code_id',
                'is_taxable', 'note', 'created_by',
            ]);
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn('non_taxable_earnings');
        });
    }
};
