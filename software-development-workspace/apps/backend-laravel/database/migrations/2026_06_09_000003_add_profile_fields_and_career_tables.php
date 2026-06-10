<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('photo_url', 500)->nullable()->after('employee_number');
            $table->text('professional_summary')->nullable()->after('country');
            $table->text('about_me')->nullable()->after('professional_summary');
            $table->text('hobbies_interests')->nullable()->after('about_me');
            $table->text('job_description')->nullable()->after('hobbies_interests');
        });

        Schema::create('employee_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('title');
            $table->string('institution')->nullable();
            $table->string('qualification_type', 50)->default('certificate');
            $table->string('field_of_study')->nullable();
            $table->smallInteger('year_obtained')->unsigned()->nullable();
            $table->string('certificate_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_secondments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('organization_name');
            $table->string('role')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_secondments');
        Schema::dropIfExists('employee_qualifications');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['photo_url', 'professional_summary', 'about_me', 'hobbies_interests', 'job_description']);
        });
    }
};
