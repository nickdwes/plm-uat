<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preboarding_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->unsignedBigInteger('candidate_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->date('expected_start_date')->nullable();
            $table->string('position')->nullable();
            $table->string('status')->default('pending');
            $table->string('token', 64)->unique()->nullable();
            $table->timestamp('welcome_sent_at')->nullable();
            $table->timestamp('announcement_sent_at')->nullable();
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('preboarding_sections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('preboarding_id');
            $table->foreign('preboarding_id')->references('id')->on('preboarding_records')->onDelete('cascade');
            $table->string('section');
            $table->string('status')->default('pending');
            $table->jsonb('data')->nullable();
            $table->text('hr_notes')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['preboarding_id', 'section']);
        });

        Schema::create('preboarding_references', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('preboarding_id');
            $table->foreign('preboarding_id')->references('id')->on('preboarding_records')->onDelete('cascade');
            $table->string('referee_name');
            $table->string('referee_email');
            $table->string('referee_company')->nullable();
            $table->string('referee_job_title')->nullable();
            $table->string('relationship')->nullable();
            $table->string('status')->default('pending');
            $table->jsonb('feedback')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('preboarding_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('preboarding_id');
            $table->foreign('preboarding_id')->references('id')->on('preboarding_records')->onDelete('cascade');
            $table->string('category');
            $table->string('original_name');
            $table->string('storage_path');
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preboarding_attachments');
        Schema::dropIfExists('preboarding_references');
        Schema::dropIfExists('preboarding_sections');
        Schema::dropIfExists('preboarding_records');
    }
};
