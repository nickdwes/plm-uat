<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a task needs before it can be done — its requirements and logistics.
 *
 * A delivery task often carries more than the work itself: someone has to
 * travel, a site visit needs transport, kit has to be procured, a vendor
 * engaged, an approval obtained. Capturing that against the task — with a cost
 * estimate, a lead time and whether it needs sign-off — is what lets a project
 * be planned, budgeted and resourced rather than surprised.
 *
 * One row per task (1:1); project_id is denormalised so the dashboards can roll
 * these up without joining through tasks on every query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_logistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();

            // What the task requires.
            $table->boolean('needs_travel')->default(false);
            $table->boolean('needs_transport')->default(false);        // to a client site
            $table->boolean('needs_accommodation')->default(false);
            $table->boolean('needs_procurement')->default(false);      // hardware / software / services
            $table->boolean('needs_special_tools')->default(false);
            $table->boolean('needs_client_access')->default(false);    // access / approvals
            $table->boolean('needs_external_vendors')->default(false);
            $table->boolean('needs_subcontractors')->default(false);

            // The detail that makes it plannable.
            $table->text('details')->nullable();
            $table->decimal('estimated_cost', 14, 2)->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->text('approval_notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('task_id');
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_logistics');
    }
};
