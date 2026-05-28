<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('review_cycle');             // Q1 2026, Annual 2025, etc.
            $table->date('review_date');
            $table->tinyInteger('rating');              // 1–5
            $table->text('goals')->nullable();
            $table->text('comments')->nullable();
            $table->text('outcomes')->nullable();
            $table->timestamps();
        });

        Schema::create('training_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('course_name');
            $table->string('provider')->nullable();
            $table->date('completion_date');
            $table->string('certification_no')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('promotion_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('from_designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->foreignId('to_designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->foreignId('from_job_grade_id')->nullable()->constrained('job_grades')->nullOnDelete();
            $table->foreignId('to_job_grade_id')->nullable()->constrained('job_grades')->nullOnDelete();
            $table->date('effective_date');
            $table->text('notes')->nullable();
            $table->string('recorded_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_history');
        Schema::dropIfExists('training_records');
        Schema::dropIfExists('performance_reviews');
    }
};
