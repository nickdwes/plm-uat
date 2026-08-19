<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mail into tickets.
 *
 * The old module's spine, rebuilt. It worked — 6,741 messages threaded onto
 * 1,792 tickets — with two faults that are not worth carrying over:
 *
 *   Threading matched on `message_id = ? or subject = ? or subject = ?`, so two
 *   customers who both wrote "Internet down" landed on one ticket and read each
 *   other's correspondence.
 *
 *   The sender whitelist was loaded and never checked, so anyone on earth could
 *   open a ticket and receive an auto-reply carrying the company's name.
 *
 * Both are addressed by the shape of these tables: real RFC message identifiers
 * to thread on, and a sender policy with somewhere for refused mail to go.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            /*
             * Whose credentials read this mailbox. Reused rather than a second
             * copy of a client secret: the connection already holds it
             * encrypted, and two places to rotate a secret means one that does
             * not get rotated.
             */
            $table->foreignId('integration_connection_id')->nullable()
                ->constrained('integration_connections')->nullOnDelete();

            $table->string('address', 200);
            $table->string('display_name', 160)->nullable();
            $table->boolean('is_active')->default(true);

            /** Whether an inbound message gets an automatic acknowledgement. */
            $table->boolean('auto_acknowledge')->default(true);

            // Where mail lands when it cannot be classified any other way.
            $table->foreignId('default_type_id')->nullable()
                ->constrained('support_ticket_types')->nullOnDelete();

            // ── Graph change-notification subscription ────────────────────────
            $table->string('subscription_id', 120)->nullable();
            $table->timestamp('subscription_expires_at')->nullable();

            /*
             * The shared secret Graph echoes back on every notification. Without
             * checking it, the webhook is an unauthenticated endpoint that
             * anybody who learns the URL can drive.
             */
            $table->text('client_state')->nullable();

            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->string('last_error', 500)->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'address']);
            $table->index('subscription_id');
        });

        /**
         * How mail is decided upon before a ticket exists.
         *
         * `allow` opens tickets. `block` drops the message and records that it
         * did. Anything unmatched is quarantined — held, visible, and not
         * turned into a ticket or an auto-reply.
         */
        Schema::create('support_sender_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // domain | address
            $table->string('kind', 12);
            $table->string('value', 200);

            // allow | block
            $table->string('action', 12)->default('allow');

            $table->string('note', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'kind', 'value']);
        });

        Schema::create('support_ticket_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('support_mailbox_id')->nullable()
                ->constrained('support_mailboxes')->nullOnDelete();

            /*
             * Nullable: quarantined mail is stored with no ticket. The old
             * system's alternative — discard anything unrecognised — means the
             * message a customer swears they sent cannot be produced.
             */
            $table->foreignId('support_ticket_id')->nullable()
                ->constrained('support_tickets')->cascadeOnDelete();

            /** The thread entry this became, where it became one. */
            $table->foreignId('support_ticket_message_id')->nullable()
                ->constrained('support_ticket_messages')->nullOnDelete();

            // inbound | outbound
            $table->string('direction', 10);

            /*
             * Graph's own identifier. Unique per mailbox, because a change
             * notification can be delivered more than once and the second
             * delivery must not become a second ticket.
             */
            $table->string('graph_message_id', 512)->nullable();

            /*
             * The RFC 5322 Message-ID, and what it was replying to. These are
             * what threading actually reads — the subject line is not evidence
             * of anything.
             */
            $table->string('internet_message_id', 512)->nullable();
            $table->string('in_reply_to', 512)->nullable();
            $table->text('references')->nullable();

            $table->string('from_name', 200)->nullable();
            $table->string('from_email', 200)->nullable();
            $table->string('subject', 512)->nullable();
            $table->text('body_preview')->nullable();
            $table->longText('body_html')->nullable();
            $table->timestamp('received_at')->nullable();

            /*
             * Why this message is not on a ticket. Null when it is. Written in
             * words an operator can act on rather than a code they must look up.
             */
            $table->string('quarantine_reason', 200)->nullable();
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'quarantine_reason']);
            $table->index('internet_message_id');
            $table->index('in_reply_to');
            $table->unique(['support_mailbox_id', 'graph_message_id'], 'support_email_once_per_mailbox');
        });

        Schema::create('support_ticket_email_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_email_id')->constrained('support_ticket_emails')->cascadeOnDelete();
            // to | cc | bcc
            $table->string('kind', 4);
            $table->string('name', 200)->nullable();
            $table->string('address', 200);
            $table->timestamps();

            $table->index(['support_ticket_email_id', 'kind']);
        });

        Schema::create('support_ticket_email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_email_id')->constrained('support_ticket_emails')->cascadeOnDelete();
            $table->string('graph_attachment_id', 512)->nullable();
            $table->string('name', 300);
            $table->string('mime_type', 160)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);

            /*
             * Nullable because the metadata is recorded the moment the message
             * is, and the bytes are fetched after. A row with no path is an
             * attachment we know about and have not downloaded — which is a
             * better state than not knowing it existed.
             */
            $table->string('storage_path', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_email_attachments');
        Schema::dropIfExists('support_ticket_email_recipients');
        Schema::dropIfExists('support_ticket_emails');
        Schema::dropIfExists('support_sender_rules');
        Schema::dropIfExists('support_mailboxes');
    }
};
