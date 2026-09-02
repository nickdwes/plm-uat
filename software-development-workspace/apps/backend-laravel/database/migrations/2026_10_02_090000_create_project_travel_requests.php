<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The travel-request register — an approvable request generated from each
 * travel cycle.
 *
 * A cycle answers "who goes where, when, for which tasks"; a request wraps that
 * with a purpose, an estimated cost and an approval state, so travel is
 * authorised before it is booked. `cycle_key` ties a request back to the cycle
 * it came from so re-generating the schedule doesn't duplicate it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_travel_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('reference')->nullable();
            $table->string('cycle_key')->nullable();

            $table->string('purpose');
            $table->string('location')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedInteger('travel_days')->nullable();
            $table->string('mode', 40)->nullable();
            $table->boolean('accommodation')->default(false);

            $table->decimal('estimated_cost', 14, 2)->nullable();
            $table->string('currency', 3)->default('KES');

            $table->json('tasks')->nullable();       // [{id,title}]
            $table->json('personnel')->nullable();   // [name]

            $table->string('approval_status', 16)->default('pending');
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('project_id');
            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_travel_requests');
    }
};
