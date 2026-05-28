<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Individual benefit definitions ────────────────────────────────────
        Schema::create('benefit_definitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->string('benefit_type'); // bonus, fuel_allowance, taxi_allowance, etc.
            $table->json('eligibility_rules')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->decimal('amount_limit', 14, 2)->nullable();
            $table->string('payable_period')->nullable(); // monthly, quarterly, annually, per_trip, per_event
            $table->boolean('paid_with_salary')->default(false);
            $table->boolean('requires_approval')->default(false);
            $table->boolean('requires_reason')->default(false);
            $table->integer('max_days')->nullable();
            $table->boolean('registration_required')->default(false);
            $table->string('mode')->nullable(); // company_issued | reimbursement | issued_amount
            $table->json('location_rates')->nullable(); // [{location, country, daily_rate}]
            $table->text('allowed_memberships')->nullable();
            $table->text('membership_rules')->nullable();
            $table->decimal('coverage_limit', 14, 2)->nullable();
            $table->boolean('dependents_required')->default(false);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
        });

        // ── Benefit plans (groups of benefits) ────────────────────────────────
        Schema::create('benefit_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('eligibility_rules')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
        });

        // ── Benefits within a plan (with optional per-plan overrides) ─────────
        Schema::create('benefit_plan_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan_id');
            $table->unsignedBigInteger('benefit_definition_id');
            $table->decimal('amount_override', 14, 2)->nullable();
            $table->decimal('limit_override', 14, 2)->nullable();
            $table->integer('max_days_override')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('plan_id')->references('id')->on('benefit_plans')->onDelete('cascade');
            $table->foreign('benefit_definition_id')->references('id')->on('benefit_definitions')->onDelete('cascade');
            $table->unique(['plan_id', 'benefit_definition_id']);
        });

        // ── Assigning benefit plans to employees ──────────────────────────────
        Schema::create('employee_benefit_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('plan_id');
            $table->date('effective_date');
            $table->date('end_date')->nullable();
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->foreign('plan_id')->references('id')->on('benefit_plans')->onDelete('cascade');
        });

        // ── Medical insurance dependents ──────────────────────────────────────
        Schema::create('benefit_dependents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->string('full_name');
            $table->string('relationship'); // spouse, child, parent, other
            $table->date('date_of_birth')->nullable();
            $table->string('id_number')->nullable();
            $table->string('supporting_doc_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
        });

        // ── Usage logs for benefits with limits ───────────────────────────────
        Schema::create('benefit_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('benefit_definition_id');
            $table->date('usage_date');
            $table->decimal('amount_used', 14, 2)->nullable();
            $table->integer('days_used')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->foreign('benefit_definition_id')->references('id')->on('benefit_definitions')->onDelete('cascade');
        });

        // ── Approval workflows — WFH, compensatory days ───────────────────────
        Schema::create('benefit_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('benefit_definition_id');
            $table->date('requested_date');
            $table->date('end_date')->nullable();
            $table->text('reason')->nullable();
            $table->integer('days_requested')->default(1);
            $table->unsignedBigInteger('supervisor_id')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->date('action_date')->nullable();
            $table->text('action_notes')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->foreign('benefit_definition_id')->references('id')->on('benefit_definitions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_approvals');
        Schema::dropIfExists('benefit_usage_logs');
        Schema::dropIfExists('benefit_dependents');
        Schema::dropIfExists('employee_benefit_plans');
        Schema::dropIfExists('benefit_plan_items');
        Schema::dropIfExists('benefit_plans');
        Schema::dropIfExists('benefit_definitions');
    }
};
