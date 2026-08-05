<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Travel reference data: destinations, expense categories, allowance rates and
 * the approval chain configuration.
 *
 * Every table here is organisation-scoped. One tenant owns a travel request
 * end to end — its destinations, the rates it is costed against, and the
 * stages it passes through are all that organisation's, and a request can
 * never be costed against another tenant's rate card or routed to another
 * tenant's approver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->char('country', 2)->nullable();          // ISO 3166-1 alpha-2
            $table->boolean('is_international')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('travel_expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');                          // Transport, Meals, Accommodation, Facilitation
            $table->string('code')->nullable();
            /*
             * Whether a receipt is expected. Kept even though no reconciliation
             * workflow is built — the legacy one held zero rows across 102
             * requests — so the intent is recorded without building a process
             * nobody used.
             */
            $table->boolean('requires_receipt')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        /*
         * The allowance rate card.
         *
         * Grade, destination and activity vary independently, so they are three
         * dimensions of one table rather than three tables.
         *
         * A null `job_grade_id` or `travel_destination_id` means "applies to
         * all", not "unset". That is what lets an organisation state one meals
         * rate globally and override it for two expensive cities without
         * enumerating every grade against every destination.
         *
         * Rates are never edited in place. A change closes the current row with
         * `effective_to` and inserts a new one, so a request approved in March
         * still shows March's rates when someone opens it in November.
         */
        Schema::create('travel_allowance_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('travel_expense_category_id')->constrained('travel_expense_categories')->cascadeOnDelete();
            $table->foreignId('job_grade_id')->nullable()->constrained('job_grades')->cascadeOnDelete();
            $table->foreignId('travel_destination_id')->nullable()->constrained('travel_destinations')->cascadeOnDelete();

            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('KES');
            $table->string('unit')->default('per_day');      // per_day | per_trip

            $table->date('effective_from');
            $table->date('effective_to')->nullable();        // null = still in force

            $table->timestamps();

            $table->index(['organization_id', 'travel_expense_category_id', 'effective_from'], 'tar_lookup_idx');
            $table->index(['organization_id', 'job_grade_id', 'travel_destination_id'], 'tar_specificity_idx');
        });

        /*
         * Which approval stages apply, and above what amount.
         *
         * The legacy encoded four stages as sixteen columns on the request.
         * As configuration, the chain can change — reordering, adding a stage,
         * raising a threshold — without a migration.
         *
         * A threshold is per currency: "50,000" means nothing without one, and
         * comparing across currencies would depend on an exchange rate nobody
         * recorded.
         */
        Schema::create('travel_approval_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('stage');                         // department_head | function_head | finance | executive
            $table->unsignedSmallInteger('sequence');
            $table->decimal('required_above_amount', 14, 2)->nullable();   // null = always required
            $table->char('currency', 3)->default('KES');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'stage']);
            $table->index(['organization_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_approval_stages');
        Schema::dropIfExists('travel_allowance_rates');
        Schema::dropIfExists('travel_expense_categories');
        Schema::dropIfExists('travel_destinations');
    }
};
