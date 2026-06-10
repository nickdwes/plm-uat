<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->unsignedBigInteger('training_item_id'); // FK to training_items.id (bigint)
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('assigned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            
            // Status: pending, approved, rejected, withdrawn
            $table->enum('status', ['pending', 'approved', 'rejected', 'withdrawn'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->text('approval_notes')->nullable();
            
            // Dates for workflow tracking
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->dateTime('withdrawn_at')->nullable();
            
            // Optional due date for the assignment
            $table->date('due_date')->nullable();
            
            // Assignment type: mandatory or recommended
            $table->enum('assignment_type', ['mandatory', 'recommended'])->default('mandatory');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Composite unique on (employee, training, org) for pending assignments
            $table->unique(['organization_id', 'training_item_id', 'employee_id'], 'unique_pending_assignment');
        });
        
        // Add foreign key after table creation using raw SQL to ensure correct type compatibility
        DB::statement('
            ALTER TABLE training_assignments
            ADD CONSTRAINT training_assignments_training_item_id_foreign
            FOREIGN KEY (training_item_id) REFERENCES training_items(id) ON DELETE CASCADE
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('training_assignments');
    }
};
