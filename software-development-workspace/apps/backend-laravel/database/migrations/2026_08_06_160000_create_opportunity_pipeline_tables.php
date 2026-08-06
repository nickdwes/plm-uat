<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The opportunity pipeline: every stage a deal is worked through.
 *
 * Three sources again. The legacy carried the whole thing on one 45-column
 * `mdl_projects_opportunities` row with a dozen integer columns pointing at a
 * file table — `tender_document`, `award_letter`, `scope_document`,
 * `final_signed_contract` and so on. Plmv2 split it into per-stage tables,
 * which is right, but kept the files as bare path strings: one nullable
 * varchar per named slot, no disk, no size, no type, no soft delete and
 * nothing standing between a path and whoever guesses it.
 *
 * Files here are rows in `opportunity_documents`, on a private disk, served
 * only through an authorised download. A tender response and a bid bond value
 * are commercially sensitive before an award and embarrassing after one; a
 * predictable URL that needs no authentication is how the candidate CVs got
 * out, and that lesson cost enough to apply once.
 *
 * The stages themselves are not a table of their own — they are the
 * opportunity_phases already seeded. What is recorded here is which of them a
 * given deal has finished, because a stage is complete when somebody says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * One document table for every stage, discriminated by `stage` and
         * `slot`, rather than a column per file.
         *
         * The legacy had fourteen file columns on the opportunity and six more
         * on the bid; Plmv2 had seventeen across its stage tables. Every one
         * of them is the same act — a person attaches a file at a point in the
         * process — and modelling it twenty times means twenty places to
         * forget the private disk.
         */
        Schema::create('opportunity_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();

            // Which stage it belongs to — site_survey, specification,
            // negotiation, proposal, bid, estimate, contracting, kickoff,
            // or `general` for the Documents tab.
            $table->string('stage', 32)->default('general');

            /*
             * The named slot within a stage, where the legacy had one.
             * `tender_checklist`, `award_letter`, `scope_of_work`. Null for
             * stages that take any number of unnamed documents.
             */
            $table->string('slot', 48)->nullable();

            // Whose document it is, which is what the tables group by.
            $table->string('document_type', 32)->default('internal');   // client | internal | financial | technical

            $table->string('label');
            $table->string('original_name');
            $table->string('storage_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            // Optional owners, so a file can hang off a bid or an estimate
            // without another table.
            $table->unsignedBigInteger('bid_id')->nullable();
            $table->unsignedBigInteger('estimate_id')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['opportunity_id', 'stage']);
            $table->index(['bid_id']);
        });

        /*
         * Which stages this deal has finished.
         *
         * A row per completed stage rather than a pointer to "how far it got",
         * because the legacy's stages are not strictly sequential — a deal can
         * be bidding while its specification is still being revised — and a
         * single high-water mark cannot say that.
         */
        Schema::create('opportunity_stage_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignId('opportunity_phase_id')->constrained('opportunity_phases')->cascadeOnDelete();

            $table->timestamp('completed_at');
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['opportunity_id', 'opportunity_phase_id']);
        });

        /*
         * What we think it will cost and earn.
         *
         * Budget, revenue, cost and profit are all held rather than deriving
         * profit from revenue minus cost: the legacy's figures frequently did
         * not reconcile, because a quoted profit is negotiated, not computed,
         * and forcing the arithmetic would silently rewrite what was agreed.
         */
        Schema::create('opportunity_estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();

            $table->string('name');
            $table->decimal('budget', 15, 2)->nullable();
            $table->decimal('revenue', 15, 2)->nullable();
            $table->decimal('cost', 15, 2)->nullable();
            $table->decimal('profit', 15, 2)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['opportunity_id']);
        });

        /*
         * What sales has committed to land, and when.
         *
         * The legacy held one committed quarter on the opportunity itself, so
         * a deal delivered in phases could only be committed once. These are
         * rows because "Phase 1, Q1–Q3" is how the commitment is actually
         * made.
         */
        Schema::create('opportunity_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();

            $table->string('name');
            $table->date('expected_start_date');
            $table->date('expected_end_date');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['opportunity_id']);
        });

        /*
         * A tender we have bid on.
         *
         * The legacy's bid carried six file columns and three free-text
         * post-mortem fields. The files move to opportunity_documents; the
         * post-mortem stays, because "why we lost" is the only part of a lost
         * bid worth keeping.
         */
        Schema::create('opportunity_bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();

            $table->string('tender_number');
            $table->string('bid_number')->nullable();
            $table->string('tender_name')->nullable();

            $table->date('tender_validity_date')->nullable();
            $table->date('bid_bond_validity_date')->nullable();
            $table->decimal('bid_bond_value', 15, 2)->nullable();

            $table->decimal('bidding_cost', 15, 2)->nullable();
            $table->decimal('bidding_value', 15, 2)->nullable();

            $table->date('tender_close_date')->nullable();
            $table->date('expected_award_date')->nullable();

            $table->string('status', 24)->default('bidding');   // bidding | won | lost | withdrawn
            $table->boolean('scope_agreed')->default(false);
            $table->text('outcome_notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['opportunity_id', 'status']);
        });

        Schema::table('opportunities', function (Blueprint $table) {
            // The board card needs both, exactly as the lead's does.
            $table->string('summary')->nullable()->after('name');
            $table->string('priority', 16)->default('medium')->after('opportunity_type');

            /*
             * Qualification, copied from the lead on conversion and editable
             * afterwards. Duplicated rather than read through the lead
             * because a deal outlives the lead's assessment — the picture at
             * bidding is not the one that justified opening it.
             */
            $table->unsignedTinyInteger('fit_company')->nullable();
            $table->unsignedTinyInteger('fit_budget')->nullable();
            $table->unsignedTinyInteger('fit_solution')->nullable();
            $table->unsignedTinyInteger('fit_timeline')->nullable();
            $table->unsignedTinyInteger('fit_stakeholder')->nullable();
            $table->string('budget_availability', 24)->nullable();
            $table->string('stakeholder_engagement', 24)->nullable();
            $table->string('timeline_estimate')->nullable();
            $table->string('technical_readiness', 24)->nullable();
            $table->string('decision_authority_access', 24)->nullable();

            $table->timestamp('qualified_at')->nullable();

            // Budget confirmation, which is what releases the deal to Commit.
            $table->decimal('confirmed_budget', 15, 2)->nullable();
            $table->timestamp('budget_confirmed_at')->nullable();
            $table->foreignId('budget_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        // The contract a won deal produced. Nullable both ways: contracts
        // exist that predate any opportunity here.
        Schema::table('account_contracts', function (Blueprint $table) {
            $table->foreignId('opportunity_id')->nullable()->after('account_id')
                ->constrained('opportunities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('account_contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opportunity_id');
        });

        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('budget_confirmed_by');
            $table->dropColumn([
                'summary', 'priority', 'fit_company', 'fit_budget', 'fit_solution',
                'fit_timeline', 'fit_stakeholder', 'budget_availability',
                'stakeholder_engagement', 'timeline_estimate', 'technical_readiness',
                'decision_authority_access', 'qualified_at', 'confirmed_budget',
                'budget_confirmed_at',
            ]);
        });

        Schema::dropIfExists('opportunity_bids');
        Schema::dropIfExists('opportunity_objectives');
        Schema::dropIfExists('opportunity_estimates');
        Schema::dropIfExists('opportunity_stage_progress');
        Schema::dropIfExists('opportunity_documents');
    }
};
