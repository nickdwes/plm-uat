<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Main learner record
        Schema::create('employee_training_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('training_item_id')->constrained('training_items')->cascadeOnDelete();
            $table->enum('assignment_type', [
                'self_assigned', 'mandatory', 'manager_assigned', 'approval_based',
            ])->default('self_assigned');
            $table->enum('status', [
                'enrolled', 'in_progress', 'completed', 'expired', 'withdrawn', 'failed',
            ])->default('enrolled');
            $table->unsignedTinyInteger('progress_percent')->default(0); // 0–100, never decreases
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('score', 5, 2)->nullable(); // exam score
            $table->boolean('is_priority')->default(false);
            $table->text('priority_reason')->nullable();
            $table->unsignedBigInteger('approval_request_id')->nullable(); // set in Phase 3
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['employee_id', 'training_item_id'], 'uniq_employee_training');
        });

        // Progress update log (one row per checkpoint logged by employee)
        Schema::create('training_progress_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_record_id')->constrained('employee_training_records')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedTinyInteger('progress_percent');
            $table->text('notes')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
        });

        // Daily check-in for long courses (duration_hours > 8)
        Schema::create('training_daily_check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_record_id')->constrained('employee_training_records')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('check_in_date');
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('hours_spent')->default(0);
            $table->timestamps();

            $table->unique(['training_record_id', 'check_in_date'], 'uniq_checkin_per_day');
        });

        // Evidence files (certificates, completion screenshots, etc.)
        Schema::create('training_evidence_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_record_id')->constrained('employee_training_records')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('category')->default('certificate'); // certificate, screenshot, other
            $table->string('original_name');
            $table->string('storage_path');
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_evidence_files');
        Schema::dropIfExists('training_daily_check_ins');
        Schema::dropIfExists('training_progress_updates');
        Schema::dropIfExists('employee_training_records');
    }
};
