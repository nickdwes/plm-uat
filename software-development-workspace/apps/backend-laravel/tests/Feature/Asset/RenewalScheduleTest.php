<?php

namespace Tests\Feature\Asset;

use App\Services\Asset\RenewalSchedule;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Six months, three months, one month, then weekly, then daily from a
 * fortnight out.
 *
 * The shape matters. The early warnings exist so a budget can be raised and a
 * vendor argued with; the late ones exist because by then somebody has to do
 * something today. A single warning at thirty days gives you neither.
 *
 * Two properties are load-bearing and easy to get wrong. A sweep that misses a
 * day must send the milestone late rather than skip it — a missed cron should
 * not cost somebody their renewal. And the same warning must never go twice,
 * because a reminder that repeats daily is a reminder people filter.
 */
class RenewalScheduleTest extends TestCase
{
    private RenewalSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schedule = new RenewalSchedule();
    }

    private function on(string $today, string $expires): ?array
    {
        return $this->schedule->due(
            CarbonImmutable::parse($expires),
            CarbonImmutable::parse($today),
        );
    }

    // ── The milestones ────────────────────────────────────────────────────

    public function test_six_months_out_is_the_first_word(): void
    {
        $this->assertSame('m6', $this->on('2026-01-01', '2026-07-01')['key']);
    }

    public function test_three_and_one_month_each_get_their_own(): void
    {
        $this->assertSame('m3', $this->on('2026-04-01', '2026-07-01')['key']);
        $this->assertSame('m1', $this->on('2026-06-01', '2026-07-01')['key']);
    }

    /** Nothing at all while the renewal is still far away. */
    public function test_a_year_out_is_quiet(): void
    {
        $this->assertNull($this->on('2025-07-01', '2026-07-01'));
    }

    /**
     * A sweep that does not run for three days sends the milestone late.
     *
     * The alternative — matching the exact day — means a missed cron silently
     * costs somebody their six-month warning, and nobody finds out until the
     * invoice arrives.
     */
    public function test_a_missed_day_sends_the_milestone_late_rather_than_never(): void
    {
        $late = $this->on('2026-01-04', '2026-07-01');

        $this->assertNotNull($late);
        $this->assertSame('m6', $late['key']);
    }

    // ── Weekly, then daily ────────────────────────────────────────────────

    /** Between a month and a fortnight, once a week. */
    public function test_inside_a_month_it_goes_weekly(): void
    {
        $due = $this->on('2026-06-10', '2026-07-01');

        $this->assertStringStartsWith('w:', $due['key']);
        $this->assertSame('soon', $due['urgency']);
    }

    /**
     * Keyed on the week, not the day.
     *
     * Two sweeps in the same week — a retry, a slipped schedule — must produce
     * the same key, or the sent-log cannot stop the second one.
     */
    public function test_two_days_in_one_week_share_a_key(): void
    {
        $this->assertSame(
            $this->on('2026-06-09', '2026-07-01')['key'],
            $this->on('2026-06-11', '2026-07-01')['key'],
        );
    }

    public function test_a_fortnight_out_it_goes_daily(): void
    {
        $due = $this->on('2026-06-20', '2026-07-01');

        $this->assertSame('d:2026-06-20', $due['key']);
        $this->assertSame('critical', $due['urgency']);
        $this->assertSame(11, $due['days_out']);
    }

    public function test_each_day_inside_the_fortnight_is_its_own(): void
    {
        $this->assertNotSame(
            $this->on('2026-06-20', '2026-07-01')['key'],
            $this->on('2026-06-21', '2026-07-01')['key'],
        );
    }

    /** The boundary itself is a daily day, not a weekly one. */
    public function test_the_fourteenth_day_is_daily(): void
    {
        $this->assertStringStartsWith('d:', $this->on('2026-06-17', '2026-07-01')['key']);
    }

    // ── After the date ────────────────────────────────────────────────────

    /**
     * It does not go quiet at the moment it comes true.
     *
     * An expired certificate is a worse problem than one about to expire, and a
     * reminder that stops on expiry stops exactly when it starts mattering.
     */
    public function test_an_expired_asset_keeps_being_chased(): void
    {
        $due = $this->on('2026-07-09', '2026-07-01');

        $this->assertSame('expired', $due['urgency']);
        $this->assertSame(-8, $due['days_out']);
        $this->assertStringStartsWith('x:', $due['key']);
    }

    // ── What somebody is signing up for ───────────────────────────────────

    public function test_the_upcoming_list_only_shows_what_is_still_ahead(): void
    {
        $upcoming = $this->schedule->upcoming(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-05-01'),
        );

        $labels = array_column($upcoming, 'label');

        // Six and three months have already passed; one month has not.
        $this->assertNotContains('6 months before', $labels);
        $this->assertContains('1 month before', $labels);
        $this->assertContains('then daily', $labels);
    }
}
