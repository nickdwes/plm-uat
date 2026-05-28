<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_goal_id')->nullable()->constrained('goals')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('level', ['individual', 'organizational'])->default('individual');
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->enum('status', ['pending', 'in_progress', 'achieved', 'not_achieved', 'on_hold'])->default('pending');
            $table->decimal('weight', 5, 2)->default(5);
            $table->date('due_date')->nullable();
            $table->decimal('completion', 5, 2)->default(0);
            $table->boolean('is_okr')->default(false);
            $table->boolean('in_library')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('goal_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['goal_id', 'employee_id']);
        });

        Schema::create('goal_key_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('use_metric')->default(false);
            $table->string('metric_format')->nullable(); // number, percentage, currency
            $table->string('metric_unit')->nullable();
            $table->decimal('start_value', 15, 2)->default(0);
            $table->decimal('target_value', 15, 2)->nullable();
            $table->decimal('current_value', 15, 2)->default(0);
            $table->decimal('completion', 5, 2)->default(0);
            $table->decimal('weight', 5, 2)->default(1);
            $table->date('due_date')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'achieved', 'not_achieved', 'on_hold'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_key_results');
        Schema::dropIfExists('goal_assignments');
        Schema::dropIfExists('goals');
    }
};
