<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deep training fulfilment. A training line on the BOM becomes an order; here
 * that order is activated into a real enablement plan — which course from the
 * catalogue, the cost per person, and the register of who is being trained,
 * each with their own travel. The parties can be internal staff or the
 * customer's people, so a trainee is not always an employee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_training_fulfillments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('project_orders')->nullOnDelete();
            $table->foreignId('training_item_id')->nullable()->constrained('training_items')->nullOnDelete();

            $table->string('title', 200);                  // course name (denormalised from the catalogue)
            $table->decimal('cost_per_person', 15, 2)->default(0);
            $table->char('currency', 3)->default('KES');
            $table->string('mode', 16)->nullable();        // virtual | classroom | hybrid
            $table->string('status', 16)->default('planned');   // planned|booked|attended|completed|certified|closed
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('project_id');
            $table->index('order_id');
        });

        Schema::create('project_trainees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_training_fulfillment_id')->constrained('project_training_fulfillments')->cascadeOnDelete();
            $table->string('party', 16)->default('internal');   // internal | customer | partner
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('name', 160);
            $table->string('email', 160)->nullable();
            $table->string('designation', 160)->nullable();

            $table->boolean('needs_travel')->default(false);
            $table->string('travel_location', 160)->nullable();
            $table->decimal('travel_cost', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('project_training_fulfillment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_trainees');
        Schema::dropIfExists('project_training_fulfillments');
    }
};
