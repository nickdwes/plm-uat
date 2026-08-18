<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who was sent their payslip, when, and what happened.
 *
 * ── Why this is recorded at all ──
 *
 * "Did she get her payslip?" is asked every month, usually by somebody who has
 * already looked in their inbox and not found it. Without a record the honest
 * answer is that nobody knows, and the usual response — send it again to
 * everybody — puts a second copy of a salary in a hundred and fifty inboxes.
 *
 * ── Held is not failed ──
 *
 * An employee with nothing to build a password from is recorded as `held`, with
 * the reason. That is not an error to retry; it is a missing record on a person
 * that somebody has to go and fix. Retrying it changes nothing and hides it
 * among genuine failures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_payslip_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained('payroll_periods')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // email | portal — one row per channel, because a payslip can reach
            // somebody by one and not the other and that difference matters.
            $table->string('channel', 16);

            // sent | failed | held
            $table->string('status', 16);

            /*
             * Where it actually went. Recorded rather than derived, because the
             * address on the employee record changes and the question asked
             * later is where it was sent at the time.
             */
            $table->string('recipient', 200)->nullable();

            $table->string('detail', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['payroll_period_id', 'status']);
            $table->unique(['payroll_period_id', 'employee_id', 'channel'], 'payslip_delivery_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_payslip_deliveries');
    }
};
