<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lead's correspondence: the calls, the mail, and what pursuing it costs.
 *
 * Three tables rather than one, because they are three different kinds of
 * record and only the tab groups them. A meeting is something we arranged, an
 * email is something that arrived, and a request is something somebody has to
 * approve before money is spent.
 *
 * Shapes follow the project's, which already does all three
 * (`project_meetings`, `project_emails`, `project_travel_requests`) — a lead is
 * an earlier stage of the same pursuit, and two screens that disagree about
 * what a meeting is would be two screens to maintain.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Teams meetings booked against the lead.
         *
         * `graph_event_id` and `join_url` come back from Graph at creation;
         * recording and transcript are pulled later, once the meeting has
         * actually run, because neither exists until then.
         */
        Schema::create('lead_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();

            $table->string('subject', 300);
            $table->timestamp('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes');
            $table->text('agenda')->nullable();

            // Name and address per attendee, as sent to Graph. Kept here so the
            // record still reads correctly after a contact is renamed or gone.
            $table->json('attendees')->nullable();

            $table->string('graph_event_id', 512)->nullable();
            $table->text('join_url')->nullable();
            $table->text('recording_url')->nullable();
            $table->longText('transcript')->nullable();

            $table->string('status', 16)->default('scheduled');   // scheduled | cancelled
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['lead_id', 'scheduled_at']);
        });

        /*
         * Mail pulled from the org's mailbox, filtered to this lead's people.
         *
         * A lead has no mailbox of its own — nobody is going to create one per
         * lead, and 160 of them would be 160 things to decommission. What a
         * lead does have is the customer's contacts, so the mail is found by
         * who is on it rather than by which box it landed in.
         *
         * De-duplicated on the Graph message id, so a repeated pull only adds
         * what is new. `matched_address` records which of the lead's people put
         * the message here, so a reader can see why it was attached at all.
         */
        Schema::create('lead_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();

            $table->string('graph_id', 512);
            $table->string('internet_message_id', 998)->nullable();
            $table->string('matched_address', 300)->nullable();

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

            $table->unique(['lead_id', 'graph_id']);
            $table->index(['lead_id', 'received_at']);
        });

        /*
         * What pursuing this lead costs before it is a deal.
         *
         * Transport to a meeting, travel to the customer's site, lunch for the
         * room, a site survey. Real money, spent on something that may never
         * close — which is exactly why it goes through an approval rather than
         * onto an expense claim after the fact.
         *
         * Deliberately its own small record rather than a row in the org's
         * travel module: a travel request there wants an itinerary, per-diems
         * and an employee to attach them to, and none of that is knowable when
         * somebody needs a car to a first meeting on Thursday. Where an
         * approved request should become a real travel record, that is a link
         * worth building later — not a reason to make the small ask hard now.
         */
        Schema::create('lead_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->string('reference', 32)->nullable();

            $table->string('kind', 24);                 // transport | travel | lunch | site_survey | other
            $table->string('purpose');
            $table->string('location')->nullable();
            $table->date('needed_on')->nullable();
            $table->unsignedSmallInteger('people')->nullable();

            $table->decimal('estimated_cost', 14, 2)->nullable();
            $table->string('currency', 3)->default('KES');
            $table->text('notes')->nullable();

            $table->string('status', 16)->default('pending');   // pending | approved | declined | fulfilled
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['lead_id', 'status']);
            $table->unique(['organization_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_requests');
        Schema::dropIfExists('lead_emails');
        Schema::dropIfExists('lead_meetings');
    }
};
