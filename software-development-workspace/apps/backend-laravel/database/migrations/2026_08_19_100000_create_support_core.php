<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The service desk: a ticket, its classification, and the correspondence on it.
 *
 * Replacing the old PLM tickets module, which was a mailbox that grew a
 * database — 1,792 tickets and 6,741 messages, and thirteen internal notes,
 * because agents never had a private place to think.
 *
 * ── Status carries a state, not just a colour ──
 *
 * The old module had eighteen statuses and no way to ask whether any of them
 * meant the ticket was waiting on us or waiting on somebody else. Every status
 * here declares a state — open, pending, resolved, closed — because that is
 * what the SLA clock has to read to know when to stop counting. Without it a
 * ticket parked with a supplier for a fortnight breaches a target nobody could
 * have met.
 *
 * ── Priority is derived, not typed ──
 *
 * Impact and urgency were both captured in the old system and nothing combined
 * them, so priority was whatever the agent felt. The matrix makes it a lookup
 * that an organisation configures once.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Classification ────────────────────────────────────────────────────

        Schema::create('support_ticket_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('slug', 80);

            /*
             * open     — with us, clock running
             * pending  — with the customer or a third party, clock paused
             * resolved — fixed, awaiting confirmation or auto-close
             * closed   — done
             *
             * The whole SLA engine reads this column and nothing else about a
             * status. Statuses can be renamed and recoloured freely; what they
             * *mean* stays machine-readable.
             */
            $table->string('state', 12);

            $table->string('colour', 9)->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'state']);
        });

        Schema::create('support_ticket_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('slug', 80);
            $table->string('description', 255)->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
        });

        /*
         * Urgency is how fast the customer needs it; impact is how many people
         * or how much of the business it touches. They are different questions
         * and the old system asked both, then used neither.
         */
        foreach (['support_ticket_urgencies', 'support_ticket_impacts'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->string('name', 80);
                $table->string('slug', 80);
                // 1 is the most severe, so ordering is obvious and stable.
                $table->unsignedTinyInteger('level');
                $table->boolean('is_default')->default(false);
                $table->timestamps();

                $table->unique(['organization_id', 'slug']);
            });
        }

        Schema::create('support_ticket_priorities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('slug', 80);
            $table->unsignedTinyInteger('level');
            $table->string('colour', 9)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
        });

        /** impact × urgency → priority, configured once per organisation. */
        Schema::create('support_priority_matrix', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('impact_id')->constrained('support_ticket_impacts')->cascadeOnDelete();
            $table->foreignId('urgency_id')->constrained('support_ticket_urgencies')->cascadeOnDelete();
            $table->foreignId('priority_id')->constrained('support_ticket_priorities')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'impact_id', 'urgency_id'], 'support_matrix_unique');
        });

        // ── The ticket ────────────────────────────────────────────────────────

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Quoted to the customer and searched on constantly.
            $table->string('reference', 24);

            /*
             * Which client this is for. Nullable because a ticket can arrive
             * from an address nobody has matched to an account yet, and holding
             * it up until somebody does would lose it.
             */
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();

            /*
             * Which contract governs it. Nothing reads this yet; the SLA engine
             * will, and adding the column now costs nothing while backfilling
             * it across live tickets later would cost a migration and a guess.
             */
            $table->foreignId('account_contract_id')->nullable()
                ->constrained('account_contracts')->nullOnDelete();

            $table->string('subject', 300);
            $table->longText('body')->nullable();

            /*
             * Who asked. Held as name and address as well as a link, because
             * mail arrives from people who have no login and may never have
             * one — and the address it came from is the fact, while the link is
             * an inference that can be corrected later.
             */
            $table->string('requester_name', 160)->nullable();
            $table->string('requester_email', 200)->nullable();
            $table->foreignId('requester_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('account_contact_id')->nullable()
                ->constrained('account_contacts')->nullOnDelete();

            $table->foreignId('ticket_type_id')->nullable()->constrained('support_ticket_types')->nullOnDelete();
            $table->foreignId('ticket_status_id')->constrained('support_ticket_statuses');
            $table->foreignId('urgency_id')->nullable()->constrained('support_ticket_urgencies')->nullOnDelete();
            $table->foreignId('impact_id')->nullable()->constrained('support_ticket_impacts')->nullOnDelete();
            $table->foreignId('priority_id')->nullable()->constrained('support_ticket_priorities')->nullOnDelete();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            /*
             * A major incident is one fault and forty reports of it. Nothing
             * drives this yet, but a ticket table without it forces a migration
             * on live data the first time there is an outage.
             */
            $table->foreignId('parent_ticket_id')->nullable()
                ->constrained('support_tickets')->nullOnDelete();

            // email | portal | phone | agent
            $table->string('source', 16)->default('agent');

            $table->timestamp('opened_at')->useCurrent();

            /*
             * Set from the first public message an agent sends, because that is
             * what a first response *is*. The old module had a field an agent
             * could set by pressing a button, which measured the button.
             */
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'ticket_status_id']);
            $table->index(['organization_id', 'assigned_to']);
            $table->index(['organization_id', 'account_id']);
            $table->index('requester_email');
        });

        // ── Correspondence ────────────────────────────────────────────────────

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();

            /*
             * public   — the customer sees it
             * internal — they never do
             *
             * First-class rather than a flag bolted on, because the old module
             * had no private channel at all and its thirteen notes across 1,792
             * tickets are what that costs: agents wrote nothing down.
             */
            $table->string('visibility', 10)->default('public');

            $table->longText('body');

            // The author, held both ways for the same reason as the requester.
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name', 160)->nullable();
            $table->string('author_email', 200)->nullable();

            // email | portal | agent | system
            $table->string('source', 16)->default('agent');

            /*
             * True when an agent wrote it. Recorded rather than derived from
             * whether author_user_id is set: an agent replying from their phone
             * by email is still an agent reply, and first response depends on
             * getting this right.
             */
            $table->boolean('is_from_agent')->default(false);

            $table->timestamps();

            $table->index(['support_ticket_id', 'visibility']);
        });

        // ── Time ──────────────────────────────────────────────────────────────

        /*
         * Captured from the start, deliberately without any billing logic.
         *
         * Whether support hours are billed against a contract is an open
         * question, and the answer is easy to ignore if the data is there and
         * impossible to reconstruct if it is not.
         */
        Schema::create('support_ticket_worklogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedInteger('minutes');
            $table->date('performed_on');
            $table->string('note', 255)->nullable();

            /*
             * Not "billable": that word implies a rate and an invoice, neither
             * of which exists. It records whether the work was done under the
             * contract or outside it, which is the fact an agent can actually
             * report at the time.
             */
            $table->boolean('under_contract')->default(true);

            $table->timestamps();

            $table->index(['support_ticket_id', 'performed_on']);
            $table->index(['user_id', 'performed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_worklogs');
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('support_priority_matrix');
        Schema::dropIfExists('support_ticket_priorities');
        Schema::dropIfExists('support_ticket_impacts');
        Schema::dropIfExists('support_ticket_urgencies');
        Schema::dropIfExists('support_ticket_types');
        Schema::dropIfExists('support_ticket_statuses');
    }
};
