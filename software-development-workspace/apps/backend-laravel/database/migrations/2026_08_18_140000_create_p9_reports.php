<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A P9 as a thing that exists, rather than a query somebody runs.
 *
 * ── Why it needs a record ──
 *
 * The P9 was computed on demand from the year's payroll runs. That answers
 * "what would a P9 say today", which is not the question anybody asks. The
 * questions are: has this year's been finished, who prepared it, when was it
 * last touched, and was it sent — none of which a query can answer, because
 * none of them are properties of the payroll runs.
 *
 * The designs draw exactly that: a list with a reference, a year, an employee
 * count, a status of In Progress or Completed, and a last-updated date.
 *
 * ── Still derived, deliberately ──
 *
 * The figures are not copied in here. They stay computed from the runs, because
 * a P9 that disagrees with the payslips it was built from is worse than no P9.
 * What is stored is the *state* of the exercise — the wrapper, not the numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_p9_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('tax_year');

            /*
             * The reference on the designs. Assigned once and kept, because it
             * is what somebody quotes in an email six months later.
             */
            $table->string('reference', 20);

            // in_progress | completed
            $table->string('status', 20)->default('in_progress');

            /*
             * Employees counted at the moment it was last generated. Stored so
             * the list can be read without recomputing a year of payroll for
             * every row, and so a change is visible: 240 last month and 238 now
             * is a question worth asking.
             */
            $table->unsignedInteger('employee_count')->default(0);

            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('employees')->nullOnDelete();

            $table->timestamps();

            // One per year per organisation: two P9s for 2026 is two answers to
            // one question, and somebody would file the wrong one.
            $table->unique(['organization_id', 'tax_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_p9_reports');
    }
};
