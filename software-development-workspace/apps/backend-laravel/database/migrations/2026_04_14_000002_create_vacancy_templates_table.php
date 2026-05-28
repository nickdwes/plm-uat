<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancy_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('workflow_id')->nullable()->constrained('recruitment_workflows')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->string('name');
            $table->string('job_title');
            $table->string('location')->nullable();
            $table->unsignedSmallInteger('default_positions')->default(1);
            $table->json('application_form_schema')->nullable(); // array of FormFieldSchema objects
            $table->json('screening_rules')->nullable();          // keyword/experience rules
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancy_templates');
    }
};
