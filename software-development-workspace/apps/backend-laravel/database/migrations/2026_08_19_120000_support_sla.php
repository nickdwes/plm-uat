<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service levels, and a clock honest enough to measure them.
 *
 * The old system had 35 SLAs and 163 packages defined under contracts, and its
 * ticket code read `$t->urgency->response_time` — a column that does not exist
 * on that table. Every SLA figure it ever showed was computed from null and
 * skipped in silence. Where the arithmetic did run it compared the allowed
 * window against the elapsed time the wrong way round, so a breach counted as
 * a success.
 *
 * None of that is ported. What is ported is the shape that was right: targets
 * belong to a contract, and escalation climbs HOD → CTO → CEO.
 *
 * ── Why a calendar ──
 *
 * The old clock was raw datetime arithmetic. A ticket raised at 5pm on Friday
 * burned a four-hour target before anybody was at a desk, and every Monday
 * morning began with breaches nobody could have prevented. Elapsed time here is
 * working time, against a calendar that knows about weekends and holidays.
 *
 * ── Why pause states ──
 *
 * A ticket parked with a supplier for a fortnight is not a fortnight of our
 * failure. The clock stops while the status is `pending` and the pause is
 * recorded, so the difference between elapsed and counted is always
 * explainable — that difference is the most-disputed number in any service
 * review.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Working time ──────────────────────────────────────────────────────

        Schema::create('support_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('timezone', 64)->default('Africa/Nairobi');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        /**
         * One working window per weekday.
         *
         * A day with no row is a day nobody is at a desk, which is how a
         * 24×7 calendar and an 8×5 calendar differ without a flag to get wrong:
         * 24×7 is simply seven rows of midnight to midnight.
         */
        Schema::create('support_calendar_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_calendar_id')->constrained('support_calendars')->cascadeOnDelete();
            // 0 = Sunday, matching Carbon's dayOfWeek.
            $table->unsignedTinyInteger('weekday');
            $table->time('opens_at');
            $table->time('closes_at');
            $table->timestamps();

            $table->unique(['support_calendar_id', 'weekday']);
        });

        Schema::create('support_calendar_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_calendar_id')->constrained('support_calendars')->cascadeOnDelete();
            $table->date('observed_on');
            $table->string('name', 120);
            $table->timestamps();

            $table->unique(['support_calendar_id', 'observed_on']);
        });

        // ── What was promised ─────────────────────────────────────────────────

        Schema::create('support_slas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);

            /*
             * Whose contract this belongs to. Null is the organisation's
             * fallback, so a ticket from an account with no contract still has
             * a target rather than no answer at all.
             */
            $table->foreignId('account_contract_id')->nullable()
                ->constrained('account_contracts')->cascadeOnDelete();

            $table->foreignId('support_calendar_id')->nullable()
                ->constrained('support_calendars')->nullOnDelete();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'account_contract_id']);
        });

        /**
         * The numbers, per priority.
         *
         * Minutes throughout, and working minutes at that. Hours would read
         * more naturally and would have to be converted at every comparison,
         * which is precisely where the old code went wrong.
         */
        Schema::create('support_sla_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_sla_id')->constrained('support_slas')->cascadeOnDelete();
            $table->foreignId('priority_id')->constrained('support_ticket_priorities')->cascadeOnDelete();

            $table->unsignedInteger('response_minutes');
            $table->unsignedInteger('resolution_minutes');

            // The ladder the old system already climbed, kept.
            $table->unsignedInteger('hod_escalation_minutes')->nullable();
            $table->unsignedInteger('cto_escalation_minutes')->nullable();
            $table->unsignedInteger('ceo_escalation_minutes')->nullable();

            $table->timestamps();

            $table->unique(['support_sla_id', 'priority_id']);
        });

        // ── The clock on one ticket ───────────────────────────────────────────

        Schema::create('support_ticket_slas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();

            /*
             * Which SLA and which target applied. Recorded rather than looked
             * up later: a contract renegotiated in March must not silently
             * restate what January's tickets were judged against.
             */
            $table->foreignId('support_sla_id')->nullable()->constrained('support_slas')->nullOnDelete();
            $table->foreignId('support_sla_target_id')->nullable()
                ->constrained('support_sla_targets')->nullOnDelete();

            $table->unsignedInteger('response_minutes')->nullable();
            $table->unsignedInteger('resolution_minutes')->nullable();

            /** When the promise falls due, in real time, on the calendar. */
            $table->timestamp('response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();

            /*
             * Running totals in working minutes. Kept because recomputing them
             * from the event log on every list render is the sort of thing that
             * is fine until a queue has four thousand rows in it.
             */
            $table->unsignedInteger('response_minutes_used')->default(0);
            $table->unsignedInteger('resolution_minutes_used')->default(0);
            $table->unsignedInteger('paused_minutes')->default(0);

            $table->timestamp('clock_started_at')->nullable();
            $table->timestamp('paused_at')->nullable();

            $table->boolean('response_met')->nullable();
            $table->boolean('resolution_met')->nullable();
            $table->timestamp('response_breached_at')->nullable();
            $table->timestamp('resolution_breached_at')->nullable();

            /** How far up the ladder this has gone: null, hod, cto, ceo. */
            $table->string('escalation_level', 8)->nullable();
            $table->timestamp('hod_escalated_at')->nullable();
            $table->timestamp('cto_escalated_at')->nullable();
            $table->timestamp('ceo_escalated_at')->nullable();

            $table->timestamps();

            $table->unique('support_ticket_id');
            $table->index('response_due_at');
            $table->index('resolution_due_at');
        });

        /**
         * What happened to the clock, as it happened.
         *
         * Written at the moment and never recomputed. A service review three
         * months later is argued from this table, and a figure that can be
         * re-derived differently after the fact is a figure both sides can
         * dispute.
         */
        Schema::create('support_sla_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();

            // started | paused | resumed | responded | resolved | breached | escalated
            $table->string('event', 16);

            // Which clock: response | resolution. Null for pauses, which stop both.
            $table->string('clock', 12)->nullable();

            /** Why, in words. "Status changed to With Supplier". */
            $table->string('detail', 255)->nullable();

            $table->unsignedInteger('working_minutes')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['support_ticket_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_sla_events');
        Schema::dropIfExists('support_ticket_slas');
        Schema::dropIfExists('support_sla_targets');
        Schema::dropIfExists('support_slas');
        Schema::dropIfExists('support_calendar_holidays');
        Schema::dropIfExists('support_calendar_windows');
        Schema::dropIfExists('support_calendars');
    }
};
