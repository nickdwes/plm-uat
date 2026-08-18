<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standing orders against one person's pay: a SACCO check-off, a HELB
 * repayment, a salary advance, a court order.
 *
 * Until now the only way to recover one was to type it into the adjustments
 * drawer every month, for every person, from memory. That is how somebody gets
 * recovered twice in March and not at all in April, and how a loan quietly
 * over-recovers past its principal.
 *
 * ── No balance column ──
 *
 * The outstanding figure is derived from what has actually been recovered on
 * approved payroll, not carried here and decremented. A stored balance and the
 * payslips behind it disagree the first time a period is reopened or a run
 * recomputed, and when they disagree it is the employee's money in the gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_recurring_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('payroll_deduction_code_id')->constrained('payroll_deduction_codes')->cascadeOnDelete();

            // What appears on the payslip. Taken from the code by default, but
            // editable: "Sacco Loan" and "Sacco Loan — car" are different
            // agreements to the person repaying them.
            $table->string('label', 120);

            $table->decimal('instalment', 14, 2);

            /*
             * Set for anything that ends — a loan, an advance. Null for a
             * standing check-off like union dues, which runs until stopped.
             * This is what tells recovery when to stop on its own rather than
             * when somebody notices.
             */
            $table->decimal('principal', 14, 2)->nullable();

            $table->date('starts_on');
            $table->date('ends_on')->nullable();

            // active | suspended | completed
            $table->string('status', 16)->default('active');

            /*
             * Who comes off first when there is not enough pay to satisfy
             * everything. Copied from the code so a schedule can override it —
             * a court order outranks a welfare contribution whatever the code
             * table says today.
             */
            $table->unsignedSmallInteger('priority')->default(50);

            $table->string('reference', 80)->nullable();   // loan or member number
            $table->string('note', 200)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'employee_id', 'status']);
        });

        Schema::table('payroll_run_items', function (Blueprint $table) {
            /*
             * Which standing order produced this line.
             *
             * Load-bearing twice over: it makes recovery idempotent, since a
             * run recomputed on every adjustment must update this month's line
             * rather than add another; and it is what the outstanding balance
             * is derived from, so the sum of these lines is the repayment
             * history rather than a number somebody maintains.
             */
            $table->foreignId('employee_recurring_deduction_id')->nullable()
                ->after('payroll_deduction_code_id')
                ->constrained('employee_recurring_deductions')->cascadeOnDelete();

            $table->unique(
                ['payroll_run_id', 'employee_recurring_deduction_id'],
                'run_item_one_per_schedule',
            );
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_items', function (Blueprint $table) {
            $table->dropUnique('run_item_one_per_schedule');
            $table->dropForeign(['employee_recurring_deduction_id']);
            $table->dropColumn('employee_recurring_deduction_id');
        });

        Schema::dropIfExists('employee_recurring_deductions');
    }
};
