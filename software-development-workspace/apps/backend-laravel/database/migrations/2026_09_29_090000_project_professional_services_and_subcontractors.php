<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Professional-services and subcontractor roles on a project.
 *
 * The advisory and delivery resources that are not staff: consultants, SMEs,
 * trainers, resident engineers; and the contractor firms doing cabling,
 * electrical, civil or network work. Each is a list of entries (many per role),
 * captured through the same add-and-list pattern as governance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_professional_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('role', 60);                 // consultant, sme, trainer, …
            $table->string('name');                     // person (may be external)
            $table->foreignId('assignee_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('organization')->nullable(); // their firm / provider
            $table->text('description')->nullable();
            $table->string('contact_info')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('project_id');
        });

        Schema::create('project_subcontractors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('role', 60);                 // cabling, electrical, network, …
            $table->string('company');
            $table->string('contact_person')->nullable();
            $table->string('contact_info')->nullable();
            $table->text('scope_of_work')->nullable();
            $table->string('contract_reference')->nullable();
            $table->string('approval_status', 20)->default('pending');    // pending / approved / rejected
            $table->string('onboarding_status', 20)->default('not_started'); // not_started / in_progress / complete
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_subcontractors');
        Schema::dropIfExists('project_professional_services');
    }
};
