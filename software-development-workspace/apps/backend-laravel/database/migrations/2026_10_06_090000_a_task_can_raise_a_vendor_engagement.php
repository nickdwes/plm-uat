<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record an external-vendor / professional-services / subcontractor task
 * requirement raises. Procurement and travel already had a home register; a
 * vendor engagement did not — vendors were only a registry and an onboarding
 * status, with nothing that says "this task needs one, here is where it stands".
 *
 * It is a demand record, distinct from the project's PS / subcontractor roster
 * (who is engaged overall): this is the ask a specific task made, tracked from
 * identifying a supplier through to active, and linked back to the prerequisite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_vendor_engagements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('reference')->nullable();

            $table->string('engagement_type', 32);   // external_vendor | professional_services | subcontractor
            $table->foreignId('vendor_partner_id')->nullable()->constrained('vendor_partners')->nullOnDelete();
            $table->string('vendor_name')->nullable();
            $table->text('scope')->nullable();
            $table->string('status', 20)->default('identifying');  // identifying | engaged | active | closed

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('project_id');
            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_vendor_engagements');
    }
};
