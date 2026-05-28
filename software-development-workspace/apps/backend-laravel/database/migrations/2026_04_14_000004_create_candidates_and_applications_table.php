<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->index();
            $table->string('phone')->nullable();
            $table->string('resume_path')->nullable();
            $table->json('social_links')->nullable();  // { linkedin, github, portfolio }
            $table->json('keywords')->nullable();       // tags/skills array
            $table->string('source')->nullable();       // e.g. LinkedIn, Referral, Website
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'email']);
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vacancy_id')->constrained('vacancies')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->foreignId('current_stage_id')->nullable()->constrained('workflow_stages')->nullOnDelete();
            $table->enum('status', ['active', 'shortlisted', 'rejected', 'hired', 'withdrawn'])->default('active');
            $table->json('form_data')->nullable(); // submitted application form answers
            $table->unsignedTinyInteger('screening_score')->nullable(); // 0-100
            $table->timestamp('applied_at')->useCurrent();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['vacancy_id', 'candidate_id']);
        });

        Schema::create('application_stage_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('from_stage_id')->nullable()->constrained('workflow_stages')->nullOnDelete();
            $table->foreignId('to_stage_id')->constrained('workflow_stages');
            $table->foreignId('moved_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamp('moved_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('recruitment_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->enum('event_type', ['new_application', 'stage_change', 'interview_scheduled', 'offer_extended', 'hired', 'rejected']);
            $table->json('payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_notifications');
        Schema::dropIfExists('application_stage_history');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('candidates');
    }
};
