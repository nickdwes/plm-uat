<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The procurement register — what a project (and later an asset or consumable
 * top-up) needs bought, from whom, by when, at what cost, and where its
 * approval stands.
 *
 * `context` marks what raised it (project for now; asset/consumable come with
 * the wider procurement module), and it links to a project and a task so the
 * register can be read both on the project and org-wide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('reference')->nullable();
            $table->string('context', 20)->default('project');   // project / asset / consumable

            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();

            $table->string('item');
            $table->string('category')->nullable();               // hardware / software / licence / service …
            $table->unsignedInteger('quantity')->default(1);
            $table->string('unit')->nullable();

            $table->string('vendor')->nullable();
            $table->foreignId('vendor_partner_id')->nullable()->constrained('vendor_partners')->nullOnDelete();

            $table->date('required_by')->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();

            $table->decimal('estimated_cost', 14, 2)->nullable();
            $table->string('currency', 3)->default('KES');

            $table->string('approval_status', 16)->default('pending');   // pending / approved / rejected
            $table->string('status', 16)->default('requested');          // requested / ordered / received / cancelled
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'context']);
            $table->index('project_id');
            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_requests');
    }
};
