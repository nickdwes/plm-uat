<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record of every payment file that left this system.
 *
 * "Has this month been sent to the bank?" is the same question as "did she get
 * her payslip?", and it has the same wrong answer available: nobody knows, so
 * generate it again. Uploading a second copy of a payroll file pays everybody
 * twice, which is a materially worse outcome than sending a second payslip.
 *
 * The control totals are stored with it. A bank reconciles the batch against a
 * count and a sum, and a file that was truncated in transit is caught by those
 * two numbers or not at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_payment_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained('payroll_periods')->cascadeOnDelete();

            $table->string('reference', 40)->unique();

            // Which layout was produced, so a re-download matches what the bank
            // was given rather than whatever the default is today.
            $table->string('format', 40);
            $table->string('value_date')->nullable();

            /*
             * What the bank is being asked to move. Stored rather than derived:
             * the runs behind it can be recomputed, and the question later is
             * what was in the file, not what the figures say now.
             */
            $table->unsignedInteger('record_count');
            $table->decimal('total_amount', 16, 2);

            // Who could not be included, and why — kept so the gap between the
            // payroll total and the batch total has a written explanation.
            $table->unsignedInteger('excluded_count')->default(0);
            $table->json('excluded')->nullable();

            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'payroll_period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_payment_batches');
    }
};
