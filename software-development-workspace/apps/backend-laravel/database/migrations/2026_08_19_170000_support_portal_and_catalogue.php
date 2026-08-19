<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Letting the customer in, and giving them something structured to ask for.
 *
 * ── Why links rather than accounts ──
 *
 * The plan's recommendation, adopted: email-first, read-only by signed link,
 * full accounts only for the clients who ask. Portal accounts mean an identity
 * surface for people outside the company — password resets, lockouts, somebody
 * leaving a client and keeping access — and none of that is worth building
 * before anybody has asked for a login.
 *
 * The token is stored hashed and can be revoked. Laravel's signed URLs would
 * have been less code and cannot be withdrawn: a link to somebody's
 * correspondence that stays valid until it expires is not a link you want to
 * have emailed to the wrong address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_portal_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            /*
             * A ticket-scoped link opens one conversation. A contact-scoped one
             * opens everything that contact has raised. Both exist because the
             * first belongs in an acknowledgement email and the second is what
             * somebody asks for when they want "our tickets".
             */
            $table->foreignId('support_ticket_id')->nullable()
                ->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('account_contact_id')->nullable()
                ->constrained('account_contacts')->cascadeOnDelete();

            /** Hashed. The plaintext exists once, in the email that carried it. */
            $table->string('token_hash', 64)->unique();

            $table->string('email', 200);
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'email']);
        });

        // ── The service catalogue ─────────────────────────────────────────────

        /**
         * Things a customer can ask for, with the questions worth asking up front.
         *
         * The old module received everything as prose, so "we need a mailbox"
         * arrived without a name, a start date or a manager to approve it, and
         * the first reply was always the same three questions. A form asks them
         * once.
         */
        Schema::create('support_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('slug', 160);
            $table->string('summary', 300)->nullable();
            $table->text('description')->nullable();

            /** What the resulting ticket is classified as. */
            $table->foreignId('ticket_type_id')->nullable()
                ->constrained('support_ticket_types')->nullOnDelete();
            $table->foreignId('service_category_id')->nullable()
                ->constrained('support_service_categories')->nullOnDelete();
            $table->foreignId('default_impact_id')->nullable()
                ->constrained('support_ticket_impacts')->nullOnDelete();
            $table->foreignId('default_urgency_id')->nullable()
                ->constrained('support_ticket_urgencies')->nullOnDelete();

            /*
             * The form, held whole. A definition is read and rendered as one
             * thing; a table per field would buy a join and answer no question
             * the answers table below does not answer better.
             */
            $table->json('fields')->nullable();

            $table->string('icon', 40)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            /*
             * Whether it needs somebody's sign-off. Recorded now and enforced in
             * phase seven — a catalogue built without the column would need a
             * migration across live requests the moment approvals land.
             */
            $table->boolean('needs_approval')->default(false);

            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
        });

        /**
         * What somebody actually answered.
         *
         * A row per answer rather than a blob on the ticket: "how many mailbox
         * requests named a start date inside 48 hours" is a question a desk
         * asks, and it cannot be asked of JSON without regretting it.
         */
        Schema::create('support_ticket_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('support_service_id')->nullable()
                ->constrained('support_services')->nullOnDelete();

            $table->string('field_key', 80);
            // Copied from the form as it stood, so an edited label does not
            // rewrite what a past requester was asked.
            $table->string('label', 200);
            $table->text('value')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['support_ticket_id', 'position']);
        });

        // ── Did we do a good job ──────────────────────────────────────────────

        /**
         * The satisfaction survey, ported from the old module.
         *
         * It collected 360 responses, which is a real answer rate and worth
         * keeping the shape of: a rating, three specific judgements and a free
         * comment. Asking one question gets a number nobody can act on.
         */
        Schema::create('support_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();

            $table->string('token_hash', 64)->unique();

            $table->string('sent_to', 200)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // 1–5, null until they answer.
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('satisfaction', 20)->nullable();
            $table->string('timeliness', 20)->nullable();
            $table->string('resolved_fully', 20)->nullable();
            $table->text('comment')->nullable();

            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'responded_at']);
            // One survey per ticket: asking twice about the same job is how a
            // response rate gets quietly inflated.
            $table->unique('support_ticket_id');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->foreignId('support_service_id')->nullable()->after('service_category_id')
                ->constrained('support_services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropForeign(['support_service_id']);
            $table->dropColumn('support_service_id');
        });

        Schema::dropIfExists('support_surveys');
        Schema::dropIfExists('support_ticket_answers');
        Schema::dropIfExists('support_services');
        Schema::dropIfExists('support_portal_tokens');
    }
};
