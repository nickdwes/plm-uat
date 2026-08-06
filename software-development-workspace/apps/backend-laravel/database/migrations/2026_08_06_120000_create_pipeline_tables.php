<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sales pipeline: leads, and the opportunities they become.
 *
 * Shaped from what Konvergenz actually ran — 162 leads and 130 opportunities
 * in the legacy book — rather than from the table definition, which carried a
 * good deal that was never filled in. Columns used on none of the 130 rows
 * (`timeline`, `practice_solution`) are not here; columns used on all of them
 * (`account_weight`, `phase`, `opportunity_type`, `estimated_close_date`) are.
 *
 * Two legacy columns are deliberately dropped. `status` held 'Pending' on all
 * 130 rows and `opportunity_status` held 'Review' on all 130 — a field with
 * one value answers nothing, and keeping it would invite somebody to start
 * using it for something else.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Where an opportunity has got to. A lookup rather than an enum
         * because the legacy's fourteen are Konvergenz's own sales process,
         * and another tenant's will differ.
         */
        Schema::create('opportunity_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('position')->default(0);

            // Which phases mean the deal is finished, and how. Derived from
            // the phase rather than stored twice on the opportunity, so
            // "closed" cannot disagree with "which phase is it in".
            $table->string('outcome', 16)->nullable();   // won | lost
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            // Nullable: a lead can arrive before anybody has opened a customer
            // record for whoever it came from.
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->string('reference', 32)->nullable();
            $table->string('name');
            $table->string('source')->nullable();          // Website, Referral, LinkedIn
            $table->string('status', 32)->default('new');
            $table->string('priority', 16)->default('medium');

            $table->date('deadline')->nullable();
            $table->string('website')->nullable();

            /*
             * The two halves of what the screens ask for. The legacy had a
             * single `description`; splitting them is what lets a lead be read
             * as "what is wrong" and "what we propose", which is the
             * distinction the sales conversation actually turns on.
             */
            $table->text('problem_statement')->nullable();
            $table->text('proposed_solution')->nullable();

            $table->foreignId('owner_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'reference']);
            $table->index(['account_id', 'status']);
            $table->index(['organization_id', 'status']);
        });

        // The research team on a lead, as the detail screen shows it.
        Schema::create('lead_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('role')->nullable();
            $table->timestamps();

            $table->unique(['lead_id', 'employee_id']);
        });

        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();

            // Where it came from. 133 of 162 legacy leads ended up attached to
            // one, which is why the link lives here rather than the lead
            // status pretending to carry it.
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();

            $table->string('reference', 32)->nullable();
            $table->string('name');
            $table->text('description')->nullable();

            $table->string('opportunity_type', 32)->nullable();   // new_business | renewal | upgrade

            /*
             * Which part of the business would deliver it. Held as written
             * until business units exist here, the same treatment as a
             * contract's owner — the legacy pointed at its own BU table.
             */
            $table->string('practice')->nullable();

            $table->decimal('amount', 15, 2)->nullable();
            $table->date('expected_close_date')->nullable();

            $table->foreignId('opportunity_phase_id')->nullable()->constrained('opportunity_phases')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('presale_lead_id')->nullable()->constrained('employees')->nullOnDelete();

            // ── Qualification ─────────────────────────────────────────────
            //
            // These are the score. Kept on the opportunity rather than in a
            // table of their own because they are one-to-one with it and are
            // always read together — see App\Support\Pipeline\OpportunityScore
            // for the weights, which are the legacy's.
            $table->string('account_weight', 16)->nullable();      // category_1 | category_2 | category_3
            $table->boolean('knows_procurement')->nullable();
            $table->boolean('deal_registered')->nullable();
            $table->boolean('compliance_pressure')->nullable();
            $table->boolean('oem_pressure')->nullable();
            $table->boolean('prior_deal_closed')->nullable();
            $table->boolean('alignment_with_customer')->nullable();
            $table->boolean('alignment_with_oem_financials')->nullable();
            $table->boolean('alignment_with_oem_solution')->nullable();
            $table->boolean('alignment_with_oem_support')->nullable();
            $table->boolean('budget_confirmed_by_customer')->nullable();
            $table->text('gap_analysis')->nullable();

            // ── Commit ────────────────────────────────────────────────────
            //
            // Sales committing to land it in a named quarter. Only 33 of 130
            // legacy rows carried it, so it is optional in fact as well as in
            // the schema.
            $table->boolean('is_committed')->default(false);
            $table->unsignedSmallInteger('committed_year')->nullable();
            $table->unsignedTinyInteger('committed_quarter')->nullable();
            $table->boolean('discuss_weekly')->default(false);

            $table->timestamp('closed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'reference']);
            $table->index(['account_id', 'opportunity_phase_id']);
            $table->index(['organization_id', 'expected_close_date']);
        });

        Schema::create('opportunity_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('role')->nullable();
            $table->timestamps();

            $table->unique(['opportunity_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_assignees');
        Schema::dropIfExists('opportunities');
        Schema::dropIfExists('lead_team');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('opportunity_phases');
    }
};
