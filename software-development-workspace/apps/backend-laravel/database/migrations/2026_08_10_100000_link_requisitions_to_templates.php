<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The requisition picks up the configuration that already exists.
 *
 * Recruitment already carries the whole downstream journey as configuration,
 * and it was configured before the requisition existed:
 *
 *   `recruitment_workflows` and `workflow_stages` are the candidate pipeline —
 *   Application Received, Phone Screening, Interview, Shortlisted, Offer
 *   Extended, Hired. That is exactly the board and the candidate timeline the
 *   designs draw, already set up, with a second pipeline that even ends in
 *   pre-onboarding.
 *
 *   `vacancy_templates` carry the job title, department, default positions, the
 *   `application_form_schema` the job-opening wizard asks for, the
 *   `screening_rules` behind weighted screening, and which workflow to use.
 *
 * So the requisition names a template and inherits all of it, rather than the
 * new journey growing its own copy of a pipeline and a form builder. Two
 * definitions of "the hiring process" is how a company ends up with candidates
 * on a board nobody configured.
 *
 * Nullable, because a requisition for a role nobody has templated is a normal
 * thing to raise — the template is a shortcut, not a gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('talent_requisitions', function (Blueprint $table) {
            $table->foreignId('vacancy_template_id')->nullable()->after('department_id')
                ->constrained('vacancy_templates')->nullOnDelete();

            /*
             * Which pipeline candidates will move through.
             *
             * Copied from the template at submission rather than read through
             * it, for the same reason the approval chain is: retemplating next
             * quarter must not silently move candidates already halfway down a
             * board. Null falls back to the organisation's default workflow.
             */
            $table->foreignId('workflow_id')->nullable()->after('vacancy_template_id')
                ->constrained('recruitment_workflows')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('talent_requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vacancy_template_id');
            $table->dropConstrainedForeignId('workflow_id');
        });
    }
};
