<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── interviews ────────────────────────────────────────────────────────
        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('vacancy_id')->constrained('vacancies')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('title');                             // e.g. "Technical Interview – Round 1"
            $table->enum('interview_type', ['phone', 'video', 'onsite', 'panel', 'technical', 'hr'])->default('panel');
            $table->dateTime('scheduled_at');
            $table->integer('duration_minutes')->default(60);
            $table->string('location')->nullable();              // room / Teams link / phone
            $table->text('agenda')->nullable();
            $table->enum('status', ['scheduled', 'confirmed', 'completed', 'cancelled', 'rescheduled'])->default('scheduled');
            $table->text('overall_notes')->nullable();
            $table->decimal('overall_score', 5, 2)->nullable(); // computed average
            $table->string('recommendation')->nullable();        // hire / hold / reject
            $table->timestamps();
            $table->softDeletes();
        });

        // ── interview_panelists ───────────────────────────────────────────────
        Schema::create('interview_panelists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id')->constrained('interviews')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->enum('role', ['lead', 'panelist', 'observer'])->default('panelist');
            $table->boolean('invite_sent')->default(false);
            $table->timestamp('invite_sent_at')->nullable();
            $table->boolean('confirmed')->default(false);
            $table->unique(['interview_id', 'employee_id']);
            $table->timestamps();
        });

        // ── interview_scores ──────────────────────────────────────────────────
        Schema::create('interview_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id')->constrained('interviews')->cascadeOnDelete();
            $table->foreignId('panelist_id')->constrained('employees')->cascadeOnDelete();
            // Scoring criteria (1–10 each)
            $table->decimal('personal_presentation', 4, 1)->nullable();     // Personal Presentation & Communication
            $table->decimal('training_certifications', 4, 1)->nullable();   // Training & Certifications
            $table->decimal('role_knowledge', 4, 1)->nullable();             // Role Knowledge, Skills & Experience
            $table->decimal('problem_solving', 4, 1)->nullable();            // Creative/Analytical/Problem Solving & Resourcefulness
            $table->decimal('teamskills_leadership', 4, 1)->nullable();      // Teamskills & Leadership
            $table->decimal('attitude_adaptability', 4, 1)->nullable();      // Attitude & Adaptability
            $table->decimal('personal_values', 4, 1)->nullable();            // Personal/Professional Values
            $table->decimal('interests_goals', 4, 1)->nullable();            // Interests & Goals
            // Computed totals
            $table->decimal('total', 5, 1)->nullable();
            $table->decimal('average_score', 5, 2)->nullable();
            // Qualitative feedback
            $table->text('strengths')->nullable();
            $table->text('weaknesses')->nullable();
            $table->text('remarks')->nullable();
            $table->string('recommendation')->nullable();        // hire / hold / reject
            $table->boolean('submitted')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->unique(['interview_id', 'panelist_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_scores');
        Schema::dropIfExists('interview_panelists');
        Schema::dropIfExists('interviews');
    }
};
