<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Functional Units (top of the hierarchy) ────────────────────────
        Schema::create('org_functional_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');                    // Finance, Engineering, HR …
            $table->string('code', 20)->nullable();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('organization_id');
        });

        // ── Extend departments with functional unit link & lead ────────────
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('functional_unit_id')
                  ->nullable()
                  ->after('organization_id')
                  ->constrained('org_functional_units')
                  ->nullOnDelete();
            $table->string('description')->nullable()->after('cost_center');
            $table->unsignedBigInteger('lead_employee_id')->nullable()->after('description');
        });

        // ── Business Units (within a Department) ──────────────────────────
        Schema::create('org_business_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->string('description')->nullable();
            $table->unsignedBigInteger('lead_employee_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'department_id']);
        });

        // ── Practices (within a Business Unit) ────────────────────────────
        Schema::create('org_practices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('business_unit_id')->constrained('org_business_units')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->string('description')->nullable();
            $table->unsignedBigInteger('lead_employee_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'business_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_practices');
        Schema::dropIfExists('org_business_units');
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn(['functional_unit_id', 'description', 'lead_employee_id']);
        });
        Schema::dropIfExists('org_functional_units');
    }
};
