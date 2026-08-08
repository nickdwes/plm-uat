<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Talent requisitions — asking for a role before advertising one.
 *
 * Recruitment currently starts at the vacancy: somebody creates a job opening
 * and it is live. The designs put a requisition in front of that, and the
 * reason is visible in the approvals panel — a role costs money and headcount,
 * so somebody asks, two or three people agree, and only then is it advertised.
 * A vacancy created directly is that whole conversation skipped.
 *
 * So the requisition is not a draft vacancy. It is the request and its
 * decision, and it keeps its own history after the vacancy exists: which
 * department asked, who approved, who held it and why. A vacancy inherits from
 * it and then goes its own way.
 *
 * Three tables rather than one, because they answer three different questions:
 * what was asked, who has to agree, and what happened when.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Who has to agree, and in what order.
         *
         * Configurable per department with an organisation-wide fallback, so
         * adding a fourth approver is configuration rather than a migration.
         * The designs show Project Manager → HR → CTO, which is one policy
         * among several a company that size will want.
         */
        Schema::create('requisition_approval_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            // Null is the organisation's default — the one used when a
            // department has no policy of its own.
            $table->foreignId('department_id')->nullable()->constrained('departments')->cascadeOnDelete();

            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One policy per department, and one default per organisation.
            $table->unique(['organization_id', 'department_id']);
        });

        Schema::create('requisition_approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_approval_policy_id')
                ->constrained('requisition_approval_policies')->cascadeOnDelete();

            $table->unsignedSmallInteger('position');
            // What the requester sees against the row: "HR", "CTO".
            $table->string('label');

            /*
             * How to find the person.
             *
             * `line_manager` resolves at submission time from whoever asked,
             * so it stays right when people move teams — naming a manager
             * explicitly means every reorganisation silently breaks the chain.
             * `role` finds whoever holds a role; `employee` names one person.
             */
            $table->string('resolver', 16);         // line_manager | role | employee
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->timestamps();

            $table->unique(['requisition_approval_policy_id', 'position']);
        });

        /*
         * The request itself.
         *
         * Job summary, responsibilities and qualifications are held here rather
         * than only on the vacancy, because they are what the approvers agreed
         * to. A vacancy edited afterwards must not silently rewrite what was
         * approved.
         */
        Schema::create('talent_requisitions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            // Req-2026-0048, as the designs number them.
            $table->string('reference', 24);

            $table->string('job_title');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->unsignedSmallInteger('positions')->default(1);
            $table->string('priority', 16)->default('medium');       // low | medium | high
            $table->date('target_hire_date')->nullable();
            $table->string('employment_type', 32)->nullable();       // permanent | contract | intern …
            // Free text: "1 Year". Only meaningful for fixed-term work, which
            // is why it is not a number of months on every row.
            $table->string('period', 64)->nullable();

            $table->text('job_summary')->nullable();
            $table->text('key_responsibilities')->nullable();
            $table->text('required_qualifications')->nullable();
            $table->json('skills')->nullable();

            /*
             * Where the request has got to.
             *
             * Distinct from the vacancy's own status: a requisition can be
             * Approved while its job opening is still a draft, and Closed long
             * after the vacancy filled.
             */
            $table->string('status', 24)->default('draft');
            // draft | in_approval | approved | open | on_hold | declined | closed

            $table->foreignId('requested_by')->nullable()->constrained('employees')->nullOnDelete();

            // Why it was held or declined. Both are answers somebody owes.
            $table->text('decision_reason')->nullable();

            // The job opening this became, once it has one.
            $table->foreignId('vacancy_id')->nullable()->constrained('vacancies')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'reference']);
            // The tabs across the top of the list, and the five counts above it.
            $table->index(['organization_id', 'status']);
            $table->index(['requested_by']);
        });

        /*
         * The chain, as instantiated for one requisition.
         *
         * Copied from the policy at submission rather than read through it, so
         * changing the policy next quarter does not rewrite who approved what
         * last quarter. The label is copied for the same reason.
         */
        Schema::create('requisition_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('talent_requisition_id')
                ->constrained('talent_requisitions')->cascadeOnDelete();

            $table->unsignedSmallInteger('position');
            $table->string('label');
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            // pending | approved | held | declined
            $table->string('status', 16)->default('pending');
            $table->text('reason')->nullable();
            $table->timestamp('actioned_at')->nullable();

            $table->timestamps();

            $table->unique(['talent_requisition_id', 'position']);
            // "What is waiting on me" across every requisition.
            $table->index(['employee_id', 'status']);
        });

        /*
         * The timeline down the right of the detail screen.
         *
         * Rows rather than a set of timestamp columns on the requisition. The
         * designs show it truncating on hold and resuming afterwards, so the
         * same stage can be reached twice — which a column cannot express, and
         * "held twice before approval" is exactly what somebody reviewing a
         * slow hire needs to see.
         */
        Schema::create('requisition_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('talent_requisition_id')
                ->constrained('talent_requisitions')->cascadeOnDelete();

            $table->string('status', 24);
            $table->text('note')->nullable();
            $table->foreignId('actor_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('occurred_at');

            $table->index(['talent_requisition_id', 'occurred_at']);
        });

        /*
         * The vacancy learns where it came from.
         *
         * Nullable, because openings raised before this existed have no
         * requisition and inventing one would be a lie about who approved it.
         */
        Schema::table('vacancies', function (Blueprint $table) {
            $table->foreignId('talent_requisition_id')->nullable()->after('organization_id')
                ->constrained('talent_requisitions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vacancies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('talent_requisition_id');
        });

        Schema::dropIfExists('requisition_events');
        Schema::dropIfExists('requisition_approvals');
        Schema::dropIfExists('talent_requisitions');
        Schema::dropIfExists('requisition_approval_steps');
        Schema::dropIfExists('requisition_approval_policies');
    }
};
