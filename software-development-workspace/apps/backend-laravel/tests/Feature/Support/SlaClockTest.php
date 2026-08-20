<?php

namespace Tests\Feature\Support;

use App\Models\Organization;
use App\Models\Support\Calendar;
use App\Models\Support\CalendarHoliday;
use App\Models\Support\Sla;
use App\Models\Support\SlaEvent;
use App\Models\Support\SlaTarget;
use App\Models\Support\Ticket;
use App\Models\Support\TicketImpact;
use App\Models\Support\TicketPriority;
use App\Models\Support\TicketStatus;
use App\Models\Support\TicketUrgency;
use App\Models\User;
use App\Services\Support\SlaClock;
use App\Services\Support\SupportDefaults;
use App\Services\Support\TicketService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The service-level clock.
 *
 * The old one read its targets from a column that does not exist, so every
 * figure it produced was computed from null and skipped in silence — and where
 * arithmetic did run it asked `allowed <= elapsed` and counted breaches as
 * successes. Both have tests here that would fail if they came back.
 */
class SlaClockTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Calendar $calendar;
    private Sla $sla;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        app(SupportDefaults::class)->ensureFor($this->org->id);
        $this->calendar = app(SupportDefaults::class)->defaultCalendar($this->org->id);

        $this->sla = Sla::create([
            'organization_id'     => $this->org->id,
            'name'                => 'Standard',
            'support_calendar_id' => $this->calendar->id,
            'is_default'          => true,
        ]);

        // Four hours to answer, one working day to fix, on Critical.
        SlaTarget::create([
            'support_sla_id'     => $this->sla->id,
            'priority_id'        => TicketPriority::where('name', 'Critical')->firstOrFail()->id,
            'response_minutes'   => 240,
            'resolution_minutes' => 540,
            'hod_escalation_minutes' => 300,
            'cto_escalation_minutes' => 480,
            'ceo_escalation_minutes' => 600,
        ]);
    }

    private function service(): TicketService { return app(TicketService::class); }
    private function clock(): SlaClock { return app(SlaClock::class); }

    private function critical(): Ticket
    {
        return $this->service()->open($this->org->id, [
            'subject'    => 'Link down',
            'body'       => 'No connectivity.',
            'impact_id'  => TicketImpact::where('name', 'High')->firstOrFail()->id,
            'urgency_id' => TicketUrgency::where('name', 'High')->firstOrFail()->id,
        ]);
    }

    private function statusNamed(string $name): TicketStatus
    {
        return TicketStatus::where('name', $name)->firstOrFail();
    }

    // ── The calendar ──────────────────────────────────────────────────────────

    /** Eight to five, Monday to Friday, as the plan recommended and we adopted. */
    public function test_a_working_day_is_nine_hours(): void
    {
        $minutes = $this->calendar->workingMinutesBetween(
            Carbon::parse('2026-08-19 00:00', 'Africa/Nairobi'),   // a Wednesday
            Carbon::parse('2026-08-19 23:59', 'Africa/Nairobi'),
        );

        $this->assertSame(540, $minutes);
    }

    /**
     * The old clock's worst behaviour. A ticket raised at 5pm on Friday burned
     * a four-hour target before anybody was at a desk.
     */
    public function test_the_weekend_does_not_count(): void
    {
        $friday5pm = Carbon::parse('2026-08-21 17:00', 'Africa/Nairobi');

        $this->assertSame(0, $this->calendar->workingMinutesBetween(
            $friday5pm, Carbon::parse('2026-08-24 08:00', 'Africa/Nairobi'),
        ));

        // Four hours promised on Friday evening is due at noon on Monday.
        // Compared in Nairobi explicitly: the calendar now hands back the
        // application timezone, which is the instant that gets stored.
        $due = $this->calendar->addWorkingMinutes($friday5pm, 240);

        $this->assertSame(
            '2026-08-24 12:00',
            $due->copy()->setTimezone('Africa/Nairobi')->format('Y-m-d H:i'),
        );
    }

    public function test_a_public_holiday_does_not_count(): void
    {
        CalendarHoliday::create([
            'support_calendar_id' => $this->calendar->id,
            'observed_on' => '2026-08-19', 'name' => 'Test holiday',
        ]);

        $fresh = Calendar::findOrFail($this->calendar->id);

        $this->assertSame(0, $fresh->workingMinutesBetween(
            Carbon::parse('2026-08-19 00:00', 'Africa/Nairobi'),
            Carbon::parse('2026-08-19 23:59', 'Africa/Nairobi'),
        ));
    }

    /**
     * Moveable feasts are not computed. Easter moves with the ecclesiastical
     * calendar and Eid is declared on sighting of the moon; a wrong holiday
     * silently changes every SLA that week.
     */
    public function test_only_fixed_date_holidays_are_seeded(): void
    {
        $names = $this->calendar->holidays()->pluck('name')->unique();

        $this->assertTrue($names->contains('Jamhuri Day'));
        $this->assertTrue($names->contains('Mashujaa Day'));
        $this->assertFalse($names->contains('Good Friday'));
        $this->assertFalse($names->contains('Eid al-Fitr'));
    }

    /**
     * A due date is stored in the application timezone, not the calendar's.
     *
     * Eloquent writes a Carbon by formatting it and does not convert, so a
     * Nairobi instant saved to a UTC column lands three hours late. Found by
     * running the whole desk end to end: the drift is invisible on one ticket
     * and compounds every time a clock is paused and resumed, because each
     * resume reads the wrong value back and shifts it again.
     */
    public function test_a_due_date_is_stored_in_the_application_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-19 08:10', 'Africa/Nairobi'));

        $ticket = $this->critical();
        $due    = $ticket->fresh()->sla->resolution_due_at;

        /*
         * 540 working minutes from 08:10: 530 of them reach 17:00, and the
         * remaining ten fall in the first ten minutes of the next working day.
         */
        $this->assertSame('Africa/Nairobi', $this->calendar->timezone);
        $this->assertSame(
            '2026-08-20 08:10',
            $due->copy()->setTimezone('Africa/Nairobi')->format('Y-m-d H:i'),
        );

        Carbon::setTestNow();
    }

    /** And a pause that banks no time must not move it at all. */
    public function test_pausing_and_resuming_immediately_does_not_shift_the_due_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-19 09:00', 'Africa/Nairobi'));

        $ticket = $this->critical();
        $before = $ticket->fresh()->sla->resolution_due_at->toDateTimeString();

        $this->service()->changeStatus($ticket, $this->statusNamed('With Supplier'));
        $this->service()->changeStatus($ticket->fresh(), $this->statusNamed('In Progress'));

        $this->assertSame($before, $ticket->fresh()->sla->resolution_due_at->toDateTimeString());

        Carbon::setTestNow();
    }

    /** Repeatedly, because the old drift compounded with every cycle. */
    public function test_five_pause_cycles_do_not_walk_the_due_date_forward(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-19 09:00', 'Africa/Nairobi'));

        $ticket = $this->critical();
        $before = $ticket->fresh()->sla->resolution_due_at->toDateTimeString();

        foreach (range(1, 5) as $_) {
            $this->service()->changeStatus($ticket->fresh(), $this->statusNamed('With User'));
            $this->service()->changeStatus($ticket->fresh(), $this->statusNamed('In Progress'));
        }

        $this->assertSame($before, $ticket->fresh()->sla->resolution_due_at->toDateTimeString());

        Carbon::setTestNow();
    }

    // ── Attaching ─────────────────────────────────────────────────────────────

    public function test_a_prioritised_ticket_gets_a_target_and_a_due_time(): void
    {
        $ticket = $this->critical();
        $clock  = $ticket->fresh()->sla;

        $this->assertNotNull($clock);
        $this->assertSame(240, $clock->response_minutes);
        $this->assertNotNull($clock->response_due_at);
        $this->assertNotNull($clock->resolution_due_at);
    }

    /**
     * An emailed ticket has no impact or urgency until somebody triages it, so
     * it has no target — and starting a countdown against a promise nobody made
     * would produce a breach nobody agreed to.
     */
    public function test_an_untriaged_ticket_has_no_clock(): void
    {
        $ticket = $this->service()->open($this->org->id, ['subject' => 'Something', 'source' => 'email']);

        $this->assertNull($ticket->fresh()->sla);
    }

    /** Triage is where most emailed tickets actually start their clock. */
    public function test_triaging_starts_the_clock(): void
    {
        $ticket = $this->service()->open($this->org->id, ['subject' => 'Something', 'source' => 'email']);
        $this->assertNull($ticket->fresh()->sla);

        $this->service()->reclassify($ticket, [
            'impact_id'  => TicketImpact::where('name', 'High')->firstOrFail()->id,
            'urgency_id' => TicketUrgency::where('name', 'High')->firstOrFail()->id,
        ]);

        $this->assertNotNull($ticket->fresh()->sla);
    }

    // ── The verdict ───────────────────────────────────────────────────────────

    /** Allowed against elapsed, the right way round. */
    public function test_answering_inside_the_target_is_recorded_as_met(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-19 09:00', 'Africa/Nairobi'));
        $ticket = $this->critical();

        Carbon::setTestNow(Carbon::parse('2026-08-19 10:00', 'Africa/Nairobi'));
        $this->service()->reply($ticket, ['body' => 'On it.'], User::factory()->create());

        $clock = $ticket->fresh()->sla;

        $this->assertTrue($clock->response_met);
        $this->assertSame(60, $clock->response_minutes_used);
        $this->assertNull($clock->response_breached_at);

        Carbon::setTestNow();
    }

    /**
     * The old code counted this as a success. Four hours promised, six working
     * hours taken.
     */
    public function test_answering_outside_the_target_is_recorded_as_a_breach(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-19 09:00', 'Africa/Nairobi'));
        $ticket = $this->critical();

        Carbon::setTestNow(Carbon::parse('2026-08-19 15:00', 'Africa/Nairobi'));
        $this->service()->reply($ticket, ['body' => 'Sorry for the delay.'], User::factory()->create());

        $clock = $ticket->fresh()->sla;

        $this->assertFalse($clock->response_met);
        $this->assertSame(360, $clock->response_minutes_used);
        $this->assertNotNull($clock->response_breached_at);

        // The breach is written as it happens, not derived later.
        $this->assertTrue(SlaEvent::where('support_ticket_id', $ticket->id)
            ->where('event', SlaEvent::BREACHED)->exists());

        Carbon::setTestNow();
    }

    /** A verdict once written is never revisited. */
    public function test_a_later_reply_does_not_change_the_verdict(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-19 09:00', 'Africa/Nairobi'));
        $ticket = $this->critical();
        $agent  = User::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-08-19 10:00', 'Africa/Nairobi'));
        $this->service()->reply($ticket, ['body' => 'On it.'], $agent);

        Carbon::setTestNow(Carbon::parse('2026-08-20 16:00', 'Africa/Nairobi'));
        $this->service()->reply($ticket, ['body' => 'Still on it.'], $agent);

        $this->assertTrue($ticket->fresh()->sla->response_met);
        $this->assertSame(60, $ticket->fresh()->sla->response_minutes_used);

        Carbon::setTestNow();
    }

    /**
     * Reclassification after the fact must not move the goalposts the answer
     * was already judged against.
     */
    public function test_reclassifying_after_an_answer_does_not_restate_the_target(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-19 09:00', 'Africa/Nairobi'));
        $ticket = $this->critical();

        Carbon::setTestNow(Carbon::parse('2026-08-19 10:00', 'Africa/Nairobi'));
        $this->service()->reply($ticket, ['body' => 'On it.'], User::factory()->create());

        $this->service()->reclassify($ticket->fresh(), [
            'impact_id'  => TicketImpact::where('name', 'Low')->firstOrFail()->id,
            'urgency_id' => TicketUrgency::where('name', 'Low')->firstOrFail()->id,
        ]);

        $this->assertSame(240, $ticket->fresh()->sla->response_minutes);

        Carbon::setTestNow();
    }

    // ── Pausing ───────────────────────────────────────────────────────────────

    /**
     * The recommendation adopted from the plan. A ticket parked with a supplier
     * for a fortnight is not a fortnight of our failure.
     */
    public function test_a_pending_status_stops_the_clock_and_says_why(): void
    {
        $ticket = $this->critical();

        $this->service()->changeStatus($ticket, $this->statusNamed('With Supplier'));

        $clock = $ticket->fresh()->sla;

        $this->assertTrue($clock->isPaused());
        $this->assertTrue(SlaEvent::where('support_ticket_id', $ticket->id)
            ->where('event', SlaEvent::PAUSED)
            ->where('detail', 'like', '%With Supplier%')->exists());
    }

    public function test_resuming_banks_the_paused_time_and_moves_the_due_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-19 09:00', 'Africa/Nairobi'));
        $ticket = $this->critical();
        $wasDue = $ticket->fresh()->sla->response_due_at;

        Carbon::setTestNow(Carbon::parse('2026-08-19 10:00', 'Africa/Nairobi'));
        $this->service()->changeStatus($ticket, $this->statusNamed('With User'));

        // Two working hours later.
        Carbon::setTestNow(Carbon::parse('2026-08-19 12:00', 'Africa/Nairobi'));
        $this->service()->changeStatus($ticket->fresh(), $this->statusNamed('In Progress'));

        $clock = $ticket->fresh()->sla;

        $this->assertFalse($clock->isPaused());
        $this->assertSame(120, $clock->paused_minutes);
        $this->assertTrue($clock->response_due_at->greaterThan($wasDue));

        Carbon::setTestNow();
    }

    /** Time parked does not count against the answer. */
    public function test_paused_time_is_excluded_from_the_verdict(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-19 09:00', 'Africa/Nairobi'));
        $ticket = $this->critical();

        Carbon::setTestNow(Carbon::parse('2026-08-19 10:00', 'Africa/Nairobi'));
        $this->service()->changeStatus($ticket, $this->statusNamed('With User'));

        Carbon::setTestNow(Carbon::parse('2026-08-20 10:00', 'Africa/Nairobi'));
        $this->service()->changeStatus($ticket->fresh(), $this->statusNamed('In Progress'));

        Carbon::setTestNow(Carbon::parse('2026-08-20 11:00', 'Africa/Nairobi'));
        $this->service()->reply($ticket->fresh(), ['body' => 'Back to you.'], User::factory()->create());

        $clock = $ticket->fresh()->sla;

        // Two hours of actual work across a day of waiting.
        $this->assertSame(120, $clock->response_minutes_used);
        $this->assertTrue($clock->response_met);

        Carbon::setTestNow();
    }

    // ── Resolution ────────────────────────────────────────────────────────────

    public function test_resolving_settles_the_resolution_clock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-19 09:00', 'Africa/Nairobi'));
        $ticket = $this->critical();

        Carbon::setTestNow(Carbon::parse('2026-08-19 15:00', 'Africa/Nairobi'));
        $this->service()->changeStatus($ticket->fresh(), $this->statusNamed('Resolved'));

        $clock = $ticket->fresh()->sla;

        $this->assertTrue($clock->resolution_met);
        $this->assertSame(360, $clock->resolution_minutes_used);

        Carbon::setTestNow();
    }

    // ── Which SLA applies ─────────────────────────────────────────────────────

    /** A ticket with no contract still has a target rather than no answer. */
    public function test_a_ticket_with_no_contract_falls_back_to_the_default(): void
    {
        $this->assertSame($this->sla->id, $this->clock()->slaFor($this->critical())->id);
    }

    // ── Escalation ────────────────────────────────────────────────────────────

    /** The ladder the old system had columns for and never climbed. */
    public function test_a_ticket_past_its_threshold_escalates_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-19 08:00', 'Africa/Nairobi'));
        $ticket = $this->critical();
        $ticket->update(['assigned_to' => User::factory()->create()->id]);

        // Six working hours in: past HOD at 300 minutes, short of CTO at 480.
        Carbon::setTestNow(Carbon::parse('2026-08-19 14:00', 'Africa/Nairobi'));
        $this->artisan('support:escalate')->assertSuccessful();

        $clock = $ticket->fresh()->sla;
        $this->assertSame('hod', $clock->escalation_level);
        $this->assertNotNull($clock->hod_escalated_at);

        // Running again changes nothing — each rung fires once.
        $this->artisan('support:escalate')->assertSuccessful();
        $this->assertSame(1, SlaEvent::where('support_ticket_id', $ticket->id)
            ->where('event', SlaEvent::ESCALATED)->count());

        Carbon::setTestNow();
    }

    /** Waiting on a supplier is not the desk failing, so it does not climb. */
    public function test_a_paused_ticket_does_not_escalate(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-19 08:00', 'Africa/Nairobi'));
        $ticket = $this->critical();
        $this->service()->changeStatus($ticket, $this->statusNamed('With Supplier'));

        Carbon::setTestNow(Carbon::parse('2026-08-21 14:00', 'Africa/Nairobi'));
        $this->artisan('support:escalate')->assertSuccessful();

        $this->assertNull($ticket->fresh()->sla->escalation_level);

        Carbon::setTestNow();
    }

    /** Two thresholds cleared at once goes straight to the higher rung. */
    public function test_a_long_overdue_ticket_skips_to_the_highest_rung_passed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-19 08:00', 'Africa/Nairobi'));
        $ticket = $this->critical();

        // Two working days on: past all three thresholds.
        Carbon::setTestNow(Carbon::parse('2026-08-21 12:00', 'Africa/Nairobi'));
        $this->artisan('support:escalate')->assertSuccessful();

        $this->assertSame('ceo', $ticket->fresh()->sla->escalation_level);

        Carbon::setTestNow();
    }
}
