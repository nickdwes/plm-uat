<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A scheduled call, not just a link pasted into a message.
 *
 * Kept as its own row rather than a message with a URL in it, because a call
 * has things a message doesn't: a time it's meant to happen, who was invited,
 * and — once recording/transcription lands — a recording and a transcript
 * that arrive after the fact and have to land somewhere specific to update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();

            $table->string('subject', 300);
            $table->timestamp('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes');
            $table->json('attendees'); // [{name, address}]

            // Graph identifiers — needed to cancel/update the event later,
            // and later still to correlate an online meeting's recording and
            // transcript back to this row.
            $table->string('graph_event_id')->nullable();
            $table->string('join_url', 500)->nullable();

            // recording_url/transcript are here now, unpopulated, rather than
            // added in a second migration when that pipeline ships — the
            // shape of "a call on a ticket" already includes them.
            $table->string('recording_url', 500)->nullable();
            $table->longText('transcript')->nullable();

            $table->string('status', 16)->default('scheduled'); // scheduled | cancelled
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['support_ticket_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_meetings');
    }
};
