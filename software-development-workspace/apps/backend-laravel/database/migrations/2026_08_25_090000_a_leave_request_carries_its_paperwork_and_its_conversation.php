<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things a leave request has always needed and never had.
 *
 * ── The paperwork ──
 *
 * Sick leave past a couple of days wants a doctor's note, compassionate leave
 * wants something showing why, and study leave wants the exam timetable. Today
 * none of that can be attached, so it arrives by email and the request that
 * depends on it carries no trace of it. An approver either takes it on trust
 * or goes looking through their inbox.
 *
 * These are medical certificates and death notices. They are stored through
 * DocumentVault on the same encrypted disk as employee documents, so storage
 * holds ciphertext and the only way to read one is a route that authorises the
 * caller first — the same rule the personnel file already follows.
 *
 * ── The conversation ──
 *
 * "Can you move this to the following week" is the commonest thing an approver
 * needs to say, and there has been nowhere to say it. The decision is already
 * recorded — approved_by, actioned_at, rejection_reason — but a rejection
 * reason is a verdict, not a discussion, and it can only be written once and
 * only by the person refusing.
 *
 * Messages are kept separate from that verdict on purpose. A thread is not an
 * audit trail: it can be added to after the fact by either side, whereas the
 * decision columns say what was decided and when, and must not move.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_request_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();

            /*
             * Who attached it, not which employee it concerns. An HR officer
             * uploading a note on somebody's behalf is a different fact from
             * the employee uploading their own, and only the user id can tell
             * them apart.
             */
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();

            $table->string('original_name');
            $table->string('storage_path');
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index('leave_request_id');
        });

        Schema::create('leave_request_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();

            $table->text('body');

            /*
             * Whether the person who asked for the leave may read it.
             *
             * An approver discussing cover with a colleague is not writing to
             * the applicant, and without this the only safe thread is one
             * nobody uses candidly. Defaults to visible, so a message is
             * private only when somebody says so.
             */
            $table->boolean('visible_to_employee')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Threads are read oldest-first for one request at a time.
            $table->index(['leave_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_messages');
        Schema::dropIfExists('leave_request_attachments');
    }
};
