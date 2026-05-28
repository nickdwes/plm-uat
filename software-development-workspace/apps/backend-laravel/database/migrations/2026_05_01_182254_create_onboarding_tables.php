<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. HR Induction checklist items per employee ──────────────────────
        Schema::create('onboarding_inductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('label');                       // e.g. "Organisation orientation"
            $table->boolean('done')->default(false);
            $table->string('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ── 2. Document collection checklist per employee ─────────────────────
        Schema::create('onboarding_document_checklist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('label');                       // e.g. "Offer letter signed"
            $table->boolean('done')->default(false);
            $table->string('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ── 3. Assets assigned to employee ────────────────────────────────────
        Schema::create('onboarding_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('name');                        // e.g. "Laptop Dell XPS 15"
            $table->string('serial_number')->nullable();   // asset/serial tag
            $table->date('assigned_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // ── 4. Uploaded employee documents (private storage) ──────────────────
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('category');                    // e.g. national_id, contract, certificate
            $table->string('original_name');               // original filename from upload
            $table->string('storage_path');                // path within employee_documents disk
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('onboarding_assets');
        Schema::dropIfExists('onboarding_document_checklist');
        Schema::dropIfExists('onboarding_inductions');
    }
};
