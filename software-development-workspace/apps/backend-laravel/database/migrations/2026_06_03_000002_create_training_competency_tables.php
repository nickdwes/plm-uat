<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Competency items (skills / knowledge / behaviours)
        Schema::create('training_competency_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable(); // e.g. Technical, Leadership, Soft-skills
            $table->json('proficiency_levels')->nullable(); // [{level:1,descriptor:"Novice"},...]
            $table->timestamps();
            $table->softDeletes();
        });

        // Link competencies to training items (many-to-many)
        Schema::create('training_item_competencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_item_id')->constrained('training_items')->cascadeOnDelete();
            $table->foreignId('competency_item_id')->constrained('training_competency_items')->cascadeOnDelete();
            $table->unique(['training_item_id', 'competency_item_id']);
        });

        // Role competency profiles — expected competencies for a job title / grade
        Schema::create('training_role_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('job_title');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Pivot: profile ↔ competency with required proficiency level (1–5)
        Schema::create('training_role_profile_competencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('training_role_profiles')->cascadeOnDelete();
            $table->foreignId('competency_item_id')->constrained('training_competency_items')->cascadeOnDelete();
            $table->unsignedTinyInteger('required_level')->default(1); // 1–5
            $table->unique(['profile_id', 'competency_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_role_profile_competencies');
        Schema::dropIfExists('training_role_profiles');
        Schema::dropIfExists('training_item_competencies');
        Schema::dropIfExists('training_competency_items');
    }
};
