<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Screenshots, log extracts, monitoring graphs — actually attached, not just
 * named in a text field. Modelled exactly on `training_evidence_files`: one
 * row per file, multiple files per RCA, soft-deleted rather than removed so
 * "what was submitted" survives even after someone takes something down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rca_evidence_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_rca_id')->constrained('support_ticket_rcas')->cascadeOnDelete();

            $table->string('original_name', 255);
            $table->string('storage_path', 500);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rca_evidence_files');
    }
};
