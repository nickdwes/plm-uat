<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            // Identity
            $table->string('employee_number')->unique();     // Group-unique ID, e.g. EMP-00042
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Primary organization (the org that owns and manages the record)
            $table->foreignId('primary_organization_id')->constrained('organizations');

            // Primary department and designation within primary org
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->foreignId('job_grade_id')->nullable()->constrained('job_grades')->nullOnDelete();

            // Reporting line within primary org (self-referential)
            $table->foreignId('reports_to')->nullable()->constrained('employees')->nullOnDelete();

            // Personal details
            $table->string('first_name');
            $table->string('last_name');
            $table->string('middle_name')->nullable();
            $table->string('preferred_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender')->nullable();           // male|female|other|prefer_not_to_say
            $table->string('national_id')->nullable()->unique();
            $table->string('passport_number')->nullable();
            $table->string('tax_id')->nullable();           // KRA PIN
            $table->string('nssf_number')->nullable();
            $table->string('nhif_number')->nullable();

            // Contact
            $table->string('personal_email')->nullable();
            $table->string('work_email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 3)->default('KE');

            // Employment
            $table->string('employment_type');              // full_time|part_time|contract|intern
            $table->string('status')->default('active');    // active|on_leave|suspended|exited
            $table->date('date_hired');
            $table->date('probation_end_date')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->date('date_exited')->nullable();
            $table->string('exit_reason')->nullable();

            // Whether this employee works across multiple organizations
            $table->boolean('is_shared')->default(false);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
