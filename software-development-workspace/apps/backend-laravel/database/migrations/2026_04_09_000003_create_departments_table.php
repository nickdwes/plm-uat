<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('name');
            $table->string('code')->nullable();         // e.g. FIN, HR, IT
            $table->string('cost_center')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('job_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');                     // e.g. Grade 5, Senior Manager
            $table->string('code')->nullable();
            $table->unsignedTinyInteger('level');       // 1 = lowest, higher = senior
            $table->decimal('min_salary', 14, 2)->nullable();
            $table->decimal('max_salary', 14, 2)->nullable();
            $table->string('currency', 3)->default('KES');
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('job_grade_id')->nullable()->constrained('job_grades')->nullOnDelete();
            $table->string('title');
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('designations');
        Schema::dropIfExists('job_grades');
        Schema::dropIfExists('departments');
    }
};
