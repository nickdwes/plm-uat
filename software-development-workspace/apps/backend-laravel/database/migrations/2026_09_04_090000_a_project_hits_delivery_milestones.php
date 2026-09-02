<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery milestones — the dated checkpoints a delivery is measured against,
 * kept apart from payment milestones (project_contract_milestones).
 *
 * A payment milestone answers "when do we get paid"; a delivery milestone
 * answers "what is due, and by when" — a deliverable and its target date, with
 * no money on it. Conflating the two is exactly what made the old billing table
 * try to be a schedule as well.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_delivery_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('title', 300);            // the deliverable
            $table->text('description')->nullable();
            $table->date('target_date')->nullable(); // when it is due
            $table->string('status', 16)->default('planned'); // planned | in_progress | achieved
            $table->date('achieved_on')->nullable(); // stamped when marked achieved
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('owner_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
            $table->index(['project_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_delivery_milestones');
    }
};
