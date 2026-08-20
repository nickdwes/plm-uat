<?php

namespace Tests\Feature\Support;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Support\Calendar;
use App\Models\Support\Sla;
use App\Models\Support\SlaTarget;
use App\Models\Support\Survey;
use App\Models\Support\Ticket;
use App\Models\Support\TicketEvent;
use App\Models\Support\TicketImpact;
use App\Models\Support\TicketPriority;
use App\Models\Support\TicketStatus;
use App\Models\Support\TicketUrgency;
use App\Models\User;
use App\Services\Support\ReportWindow;
use App\Services\Support\ServiceReview;
use App\Services\Support\SupportDefaults;
use App\Services\Support\TicketService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The figures a service review is argued from.
 *
 * A report is only worth having if it is honest when the news is bad, so most
 * of what follows is about the ways a number can flatter: an empty set drawn
 * as a hundred per cent, a breached ticket that escapes the denominator by
 * never being answered, and a resolved count that changes months later because
 * somebody reopened something.
 */
class ServiceReviewTest extends TestCase
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

        foreach (['Critical' => [240, 540], 'High' => [480, 1080]] as $name => [$resp, $res]) {
            SlaTarget::create([
                'support_sla_id'     => $this->sla->id,
                'priority_id'        => TicketPriority::where('name', $name)->firstOrFail()->id,
                'response_minutes'   => $resp,
                'resolution_minutes' => $res,
            ]);
        }
    }

    private function service(): TicketService { return app(TicketService::class); }
    private function review(): ServiceReview { return app(ServiceReview::class); }

    private function window(string $from = '2026-07-01', string $to = '2026-07-31'): ReportWindow
    {
        return ReportWindow::make($this->org->id, $from, $to);
    }

    private function statusNamed(string $name): TicketStatus
    {
        return TicketStatus::where('name', $name)->firstOrFail();
    }

    /** A critical ticket opened at a given Nairobi moment. */
    private function raise(string $at, array $data = []): Ticket
    {
        Carbon::setTestNow(Carbon::parse($at, 'Africa/Nairobi'));

        $ticket = $this->service()->open($this->org->id, array_merge([
            'subject'    => 'Link down',
            'body'       => 'No connectivity.',
            'impact_id'  => TicketImpact::where('name', 'High')->firstOrFail()->id,
            'urgency_id' => TicketUrgency::where('name', 'High')->firstOrFail()->id,
        ], $data));

        return $ticket;
    }

    private function answerAt(Ticket $ticket, string $at): void
    {
        Carbon::setTestNow(Carbon::parse($at, 'Africa/Nairobi'));
        $this->service()->reply($ticket, ['body' => 'Looking now.', 'is_from_agent' => true]);
    }

    private function resolveAt(Ticket $ticket, string $at): Ticket
    {
        Carbon::setTestNow(Carbon::parse($at, 'Africa/Nairobi'));

        return $this->service()->changeStatus($ticket->fresh(), $this->statusNamed('Resolved'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── An empty set has no rate ──────────────────────────────────────────────

    /**
     * Nought of nought is not a hundred per cent.
     *
     * The single most common way a service report lies: an account that raised
     * nothing shows perfect compliance, and nobody notices until the month it
     * matters.
     */
    public function test_a_month_with_no_tickets_has_no_compliance_figure(): void
    {
        $sla = $this->review()->compliance($this->window());

        $this->assertNull($sla['response']['rate']);
        $this->assertNull($sla['resolution']['rate']);
        $this->assertSame(0, $sla['response']['total']);
    }

    public function test_every_rate_carries_the_count_it_came_from(): void
    {
        $one = $this->raise('2026-07-06 09:00');
        $this->answerAt($one, '2026-07-06 10:00');

        $sla = $this->review()->compliance($this->window());

        $this->assertSame(1, $sla['response']['total']);
        $this->assertSame(1, $sla['response']['met']);
        $this->assertSame(100.0, $sla['response']['rate']);
    }

    // ── The tickets that would otherwise escape ───────────────────────────────

    /**
     * A ticket that blew its target and was never answered still counts.
     *
     * Counting only tickets that were answered in the window quietly excuses
     * the worst ones on the desk — the longer nobody touches a ticket, the
     * longer it stays out of the figure it belongs in.
     */
    public function test_a_breached_ticket_nobody_answered_counts_as_a_miss(): void
    {
        $ticket = $this->raise('2026-07-06 09:00');

        // Four working hours to answer; nobody does, and the hourly sweep is
        // what stamps the breach.
        Carbon::setTestNow(Carbon::parse('2026-07-07 09:00', 'Africa/Nairobi'));
        app(\App\Services\Support\SlaClock::class)->markPassedTargets();

        $sla = $this->review()->compliance($this->window());

        $this->assertSame(1, $sla['response']['total'], 'the unanswered breach must be in the population');
        $this->assertSame(0, $sla['response']['met']);
        $this->assertSame(1, $sla['response']['missed']);
        $this->assertSame(1, $sla['response']['outstanding'], 'and be marked as still open');
        $this->assertSame(0.0, $sla['response']['rate']);
    }

    /** Tickets with no SLA are held out — and said out loud. */
    public function test_tickets_with_no_sla_are_excluded_by_name(): void
    {
        $this->sla->update(['is_active' => false]);

        $this->raise('2026-07-06 09:00');

        $sla = $this->review()->compliance($this->window());

        $this->assertSame(0, $sla['response']['total']);
        $this->assertSame(1, $sla['excluded']['no_sla']);
        $this->assertStringContainsString('no SLA', $sla['excluded']['note']);
    }

    /** A merged ticket's work belongs to the ticket it was folded into. */
    public function test_merged_tickets_are_not_counted_twice(): void
    {
        $keep  = $this->raise('2026-07-06 09:00');
        $dupe  = $this->raise('2026-07-06 09:30');

        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00', 'Africa/Nairobi'));
        app(\App\Services\Support\MajorIncidents::class)->merge($dupe->fresh(), $keep->fresh());

        $volumes = $this->review()->volumes($this->window());

        $this->assertSame(1, $volumes['raised']);
        $this->assertSame(1, $this->review()->compliance($this->window())['excluded']['merged']);
    }

    // ── The figures must not move ─────────────────────────────────────────────

    /**
     * Reopening an old ticket must not rewrite the month it was resolved in.
     *
     * `resolved_at` is cleared when a ticket comes back — correctly, since a
     * resolution date on an open ticket is a false fact. Counting from it meant
     * July's report changed in August. The event trail is written once.
     */
    public function test_reopening_a_ticket_does_not_change_last_months_figures(): void
    {
        $ticket = $this->raise('2026-07-06 09:00');
        $this->answerAt($ticket, '2026-07-06 09:30');
        $this->resolveAt($ticket, '2026-07-07 11:00');

        $july = $this->review()->volumes($this->window());
        $this->assertSame(1, $july['resolved']);

        // The customer says it is not fixed, three weeks later.
        Carbon::setTestNow(Carbon::parse('2026-08-04 09:00', 'Africa/Nairobi'));
        $this->service()->changeStatus($ticket->fresh(), $this->statusNamed('In Progress'));

        $this->assertNull($ticket->fresh()->resolved_at, 'the column is rightly cleared');
        $this->assertSame(1, $this->review()->volumes($this->window())['resolved'],
            'but July still resolved one ticket');

        $august = $this->review()->volumes($this->window('2026-08-01', '2026-08-31'));
        $this->assertSame(1, $august['reopened']);
        $this->assertSame(0, $august['resolved']);
    }

    /**
     * The list of misses must include the ones nobody ever got to.
     *
     * A "where we were slowest" table built only from tickets that were
     * eventually fixed omits the tickets that were never fixed — which are
     * worse by every measure, and are the ones the customer brings to the
     * meeting.
     */
    public function test_the_slowest_list_names_the_tickets_nobody_ever_fixed(): void
    {
        $late = $this->raise('2026-07-06 09:00');
        $this->answerAt($late, '2026-07-06 09:10');
        $this->resolveAt($late, '2026-07-14 16:00');       // nine hours' target, days taken

        $never = $this->raise('2026-07-07 09:00');
        $this->answerAt($never, '2026-07-07 09:10');

        Carbon::setTestNow(Carbon::parse('2026-07-31 09:00', 'Africa/Nairobi'));
        app(\App\Services\Support\SlaClock::class)->markPassedTargets();

        $worst = $this->review()->compliance($this->window())['worst'];

        $this->assertCount(2, $worst);
        $this->assertSame($never->reference, $worst[0]['reference'], 'still open comes first');
        $this->assertTrue($worst[0]['still_open']);
        $this->assertNull($worst[0]['took'], 'a ticket still running has no time taken');
        $this->assertSame($late->reference, $worst[1]['reference']);
        $this->assertGreaterThan(0, $worst[1]['over_by']);
    }

    // ── Waiting times ─────────────────────────────────────────────────────────

    /**
     * The median, not the mean.
     *
     * Four tickets answered quickly and one after a long weekend: the mean says
     * everybody waited hours, the median says what four of the five actually
     * got. A wait time's mean describes nobody.
     */
    public function test_response_times_are_reported_as_a_median_and_a_tail(): void
    {
        foreach (['09:00', '09:30', '10:00', '10:30'] as $i => $at) {
            $t = $this->raise("2026-07-0" . ($i + 6) . " {$at}");
            $this->answerAt($t, "2026-07-0" . ($i + 6) . " " . substr($at, 0, 2) . ":15");
        }

        // The one that went badly: raised Friday evening, answered Monday.
        $slow = $this->raise('2026-07-10 16:30');
        $this->answerAt($slow, '2026-07-13 16:00');

        $times = $this->review()->responseTimes($this->window());

        $this->assertSame(5, $times['response']['count']);
        $this->assertLessThan(60, $times['response']['median'], 'most people waited minutes');
        $this->assertGreaterThan(400, $times['response']['worst'], 'one waited working days');
        $this->assertSame($times['response']['worst'], $times['response']['p90']);
    }

    public function test_waiting_times_are_absent_rather_than_zero_when_nothing_was_answered(): void
    {
        $times = $this->review()->responseTimes($this->window());

        $this->assertSame(0, $times['response']['count']);
        $this->assertNull($times['response']['median']);
    }

    // ── The window ────────────────────────────────────────────────────────────

    /**
     * A month asked for in Nairobi is a month in Nairobi.
     *
     * The database stores UTC and the desk works East Africa Time. A ticket
     * raised at half past eleven on the 31st is stored as the 31st at 20:30
     * UTC; a naive range ending at "2026-07-31" would drop it into nowhere, and
     * one raised at 9am on the 1st would fall out of the other end.
     */
    public function test_the_last_evening_of_the_month_is_in_the_month(): void
    {
        $this->raise('2026-07-31 23:30');
        $this->raise('2026-07-01 08:15');
        $this->raise('2026-08-01 08:15');

        $this->assertSame(2, $this->review()->volumes($this->window())['raised']);
        $this->assertSame(1, $this->review()->volumes(
            $this->window('2026-08-01', '2026-08-31'))['raised']);
    }

    // ── Satisfaction ──────────────────────────────────────────────────────────

    /** A score is meaningless without the share of people who gave one. */
    public function test_satisfaction_reports_its_response_rate(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 09:00', 'Africa/Nairobi'));

        $answered = $this->raise('2026-07-06 09:00');
        $ignored  = $this->raise('2026-07-07 09:00');

        Carbon::setTestNow(Carbon::parse('2026-07-15 09:00', 'Africa/Nairobi'));
        Survey::issue($answered, 'ann@client.co.ke');
        Survey::issue($ignored, 'bob@client.co.ke');

        Survey::where('support_ticket_id', $answered->id)->update([
            'rating' => 5, 'satisfaction' => 'yes', 'responded_at' => now(),
        ]);

        $csat = $this->review()->satisfaction($this->window());

        $this->assertSame(2, $csat['sent']);
        $this->assertSame(1, $csat['responded']);
        $this->assertSame(50.0, $csat['response_rate']);
        $this->assertSame(5.0, $csat['average']);
        $this->assertStringContainsString('Too few responses', $csat['note']);
    }

    public function test_a_score_nobody_gave_is_absent_not_zero(): void
    {
        $csat = $this->review()->satisfaction($this->window());

        $this->assertNull($csat['average']);
        $this->assertNull($csat['response_rate']);
    }

    // ── Backlog ───────────────────────────────────────────────────────────────

    public function test_the_backlog_separates_our_delay_from_the_customers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 09:00', 'Africa/Nairobi'));

        $ours = $this->raise('2026-07-01 09:00');

        $theirs = $this->raise('2026-07-02 09:00');
        Carbon::setTestNow(Carbon::parse('2026-07-03 09:00', 'Africa/Nairobi'));
        $this->service()->changeStatus($theirs->fresh(), $this->statusNamed('With User'));

        Carbon::setTestNow(Carbon::parse('2026-07-20 09:00', 'Africa/Nairobi'));

        $backlog = $this->review()->backlog($this->window());

        $this->assertSame(2, $backlog['total']);
        $this->assertSame(2, $backlog['unassigned']);

        $old = collect($backlog['buckets'])->firstWhere('label', '2–4 weeks');
        $this->assertSame(2, $old['total']);
        $this->assertSame(1, $old['with_customer'], 'one of them is waiting on them, not us');

        $this->assertSame($ours->reference, $backlog['oldest'][0]['reference']);
    }

    // ── Who may read it ───────────────────────────────────────────────────────

    private function signIn(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Desk', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    private function headers(): array
    {
        return ['X-Organization' => $this->org->slug, 'Accept' => 'application/json'];
    }

    public function test_answering_tickets_does_not_grant_every_accounts_figures(): void
    {
        $this->signIn(['support.view', 'support.update', 'support.close']);

        $this->getJson('/api/v1/support/reports/service-review', $this->headers())
            ->assertForbidden();
    }

    public function test_the_report_permission_does_not_require_managing_the_desk(): void
    {
        $this->signIn(['support.report']);

        $this->getJson('/api/v1/support/reports/service-review', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.window.timezone', 'Africa/Nairobi');
    }

    /**
     * The export must agree with the report.
     *
     * The first thing anybody does with a service review is count the rows in
     * the spreadsheet, and a list that disagrees with the slide discredits
     * both.
     */
    public function test_the_export_lists_exactly_the_tickets_behind_the_figures(): void
    {
        $keep = $this->raise('2026-07-06 09:00');
        $dupe = $this->raise('2026-07-06 09:30');

        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00', 'Africa/Nairobi'));
        app(\App\Services\Support\MajorIncidents::class)->merge($dupe->fresh(), $keep->fresh());

        $this->raise('2026-08-03 09:00');

        $this->signIn(['support.report']);

        $csv = $this->get('/api/v1/support/reports/export?from=2026-07-01&to=2026-07-31',
            $this->headers())->assertOk()->streamedContent();

        $rows = array_values(array_filter(explode("\n", trim($csv))));

        // Title, header, then one ticket: the merged one and August's are both
        // out, exactly as the volumes say.
        $this->assertCount(3, $rows);
        $this->assertStringContainsString($keep->reference, $rows[2]);
        $this->assertStringNotContainsString($dupe->reference, $csv);
    }

    public function test_a_ticket_with_no_outcome_yet_is_not_recorded_as_a_miss(): void
    {
        $this->raise('2026-07-06 09:00');
        $this->signIn(['support.report']);

        $csv = $this->get('/api/v1/support/reports/export?from=2026-07-01&to=2026-07-31',
            $this->headers())->assertOk()->streamedContent();

        $this->assertStringContainsString('Still running', $csv);
        $this->assertStringNotContainsString('Missed', $csv);
    }
}
