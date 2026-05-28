<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // for public apply URLs
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('vacancy_templates')->nullOnDelete();
            $table->foreignId('workflow_id')->constrained('recruitment_workflows');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->foreignId('hiring_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('job_title');
            $table->string('location')->nullable();
            $table->unsignedSmallInteger('open_positions')->default(1);
            $table->enum('status', ['draft', 'open', 'closed', 'cancelled'])->default('draft');
            $table->json('application_form_schema')->nullable(); // overrideable from template
            $table->text('description')->nullable();
            $table->date('closing_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancies');
    }
};
