<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One definition of a secondment, and the handful of fields the wizard asks for
 * that nothing else did.
 *
 * ── Secondments existed twice ──
 *
 * `employee_secondments` held an organisation as free text, a role, two dates
 * and a note. `employee_organization_map` held the same idea properly: a linked
 * organisation, a designation, a department, who they report to there, an
 * allocation percentage, whether the time is billable, and an effective window.
 *
 * The designs need the second one. The Scope of Engagement panel asks for Time
 * Allocation and "Billable to Secondary Org?", and the organisation is a chip
 * you pick from the four tenants rather than a name you type. A free-text
 * column cannot answer "who is 50% billable to Tiberbu this quarter", which is
 * the only reason anybody records a secondment at all.
 *
 * So the free-text table goes. It holds no rows, so nothing is lost — and
 * leaving it would mean two places to write a secondment and no way to tell
 * which one a report should read.
 *
 * ── What the wizard asks for that nothing carried ──
 *
 * Marital status and county are on the form and had nowhere to go. Engagement
 * type is on the Scope of Engagement panel and is the one genuine gap in the
 * org map.
 *
 * The form's "Cadre / Job Grade" needs nothing: `job_grades` is already
 * configured with six rows, KNS-G1 (Level 1) among them, carrying the salary
 * bands the grade exists to define — and `employees.job_grade_id` already
 * points at it. A string column beside that would have been a second answer to
 * the same question, and the one without the pay band attached.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_organization_map', function (Blueprint $table) {
            /*
             * Why somebody is in a second organisation: seconded, shared
             * service, an advisory seat. It changes what the allocation means
             * and who is expected to pay for it, so it is not a note.
             */
            $table->string('engagement_type', 32)->nullable()->after('role');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('marital_status', 24)->nullable()->after('gender');
            // Kenyan counties. Beside the country the table already had, which
            // on its own is too coarse for anything a Kenyan employer files.
            $table->string('county', 64)->nullable()->after('city');
        });

        // Superseded by employee_organization_map, and empty.
        Schema::dropIfExists('employee_secondments');
    }

    public function down(): void
    {
        Schema::create('employee_secondments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('organization_name');
            $table->string('role')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['marital_status', 'county']);
        });

        Schema::table('employee_organization_map', function (Blueprint $table) {
            $table->dropColumn('engagement_type');
        });
    }
};
