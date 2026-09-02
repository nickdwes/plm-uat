<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The procurement approval engine — a clone of the travel chain's shape, kept
 * separate so each module's governance config can evolve on its own.
 *
 * Stages are configuration, not code: per organisation, per (optional)
 * category, ordered by sequence, each applying above an amount threshold.
 * Submitting a request materialises one approval row per applicable stage;
 * decisions walk them earliest-first; a rejection skips the rest.
 *
 * Every organisation is seeded with the standard threshold matrix in this same
 * migration, so there is never a window where a submission finds an empty
 * chain: department head from zero, finance above 50k, operations above 250k,
 * executive above 1M.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_approval_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('stage', 32);              // department_head|finance|operations|executive
            $table->string('category', 32)->nullable();   // discriminator: category-specific chain replaces the default
            $table->unsignedInteger('sequence');
            $table->decimal('required_above_amount', 14, 2)->nullable();
            $table->char('currency', 3)->default('KES');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'stage', 'category']);
        });

        Schema::create('procurement_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('procurement_request_id')->constrained('procurement_requests')->cascadeOnDelete();
            $table->string('stage', 32);
            $table->unsignedInteger('sequence');
            $table->foreignId('expected_approver_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 16)->default('pending');   // pending|approved|rejected|skipped
            $table->boolean('escalated')->default(false);       // appended by the margin gate, not the threshold
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['procurement_request_id', 'status']);
        });

        Schema::create('procurement_department_approvers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('procurement_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->decimal('margin_floor_percent', 5, 2)->nullable();          // escalate when cost base breaches it
            $table->decimal('price_match_tolerance_percent', 5, 2)->default(2); // three-way match slack
            $table->char('currency', 3)->default('KES');
            $table->timestamps();
        });

        // Seed the standard threshold matrix for every organisation.
        $now = now();
        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            DB::table('procurement_approval_stages')->insert([
                ['organization_id' => $orgId, 'stage' => 'department_head', 'category' => null, 'sequence' => 1, 'required_above_amount' => null,      'currency' => 'KES', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                ['organization_id' => $orgId, 'stage' => 'finance',         'category' => null, 'sequence' => 2, 'required_above_amount' => 50000,     'currency' => 'KES', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                ['organization_id' => $orgId, 'stage' => 'operations',      'category' => null, 'sequence' => 3, 'required_above_amount' => 250000,    'currency' => 'KES', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                ['organization_id' => $orgId, 'stage' => 'executive',       'category' => null, 'sequence' => 4, 'required_above_amount' => 1000000,   'currency' => 'KES', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_settings');
        Schema::dropIfExists('procurement_department_approvers');
        Schema::dropIfExists('procurement_approvals');
        Schema::dropIfExists('procurement_approval_stages');
    }
};
