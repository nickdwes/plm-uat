<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound email pulled from a project's shared mailbox into its correspondence.
 *
 * Stored once per project, de-duplicated on the Graph message id, so a repeated
 * pull is idempotent. Reuses the same Graph mail client the support desk uses;
 * unlike meeting recordings, Mail.Read is tenant-wide app-only and needs no
 * Teams application access policy — so this reads as soon as the connection has
 * the scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('graph_id', 512);            // Graph message id — the dedup key
            $table->string('internet_message_id', 998)->nullable();
            $table->string('from_name', 300)->nullable();
            $table->string('from_email', 300)->nullable();
            $table->string('subject', 998)->nullable();
            $table->text('body_preview')->nullable();
            $table->longText('body_html')->nullable();
            $table->json('to_recipients')->nullable();
            $table->json('cc_recipients')->nullable();
            $table->boolean('has_attachments')->default(false);
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'graph_id']);
            $table->index(['project_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_emails');
    }
};
