<?php

namespace Tests\Feature\Performance;

use App\Models\Attendance\AttendanceDay;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\PerformanceCycle;
use App\Services\Performance\CycleAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * How present somebody was over a review cycle.
 *
 * Advisory — it is shown beside the rating and computes no part of it. Most of
 * what follows is about what must *not* count against somebody, because this
 * figure ends up in a conversation about their appraisal.
 */
class CycleAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Employee $employee;
    private PerformanceCycle $cycle;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-30 09:00:00'));

        $this->org = Organization::create(['name' => 'Konvergenz', 'slug' => 'konvergenz']);
        app()->instance('current.organization_id', $this->org->id);

        $this->employee = Employee::create([
            'employee_number' => 'E1', 'primary_organization_id' => $this->org->id,
            'first_name' => 'James', 'last_name' => 'K', 'employment_type' => 'permanent',
            'date_hired' => '2024-01-01', 'status' => 'active',
        ]);

        $this->cycle = PerformanceCycle::create([
            'organization_id' => $this->org->id, 'name' => 'H1 2026',
            'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30', 'is_open' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function days(string $status, int $count, string $from = '2026-02-02'): void
    {
        $date = Carbon::parse($from);

        for ($i = 0; $i < $count; $i++) {
            AttendanceDay::create([
                'organization_id' => $this->org->id,
                'employee_id'     => $this->employee->id,
                'work_date'       => $date->copy()->addDays($i)->toDateString(),
                'status'          => $status,
                'planned_location' => 'office',
            ]);
        }
    }

    private function measure(): array
    {
        return (new CycleAttendance())->for($this->employee->id, $this->cycle);
    }

    public function test_the_rate_carries_its_denominator(): void
    {
        $this->days('on_time', 90);
        $this->days('absent', 10, '2026-05-04');

        $result = $this->measure();

        $this->assertSame(90, $result['present']);
        $this->assertSame(10, $result['absent']);
        $this->assertSame(100, $result['expected']);
        $this->assertEqualsWithDelta(90.0, $result['rate'], 0.01);
    }

    /**
     * The rule that matters most. Somebody on signed-off leave is not absent
     * in any sense a review should weigh — and leaving those days in the
     * denominator would mean the more leave you are entitled to, the worse
     * you look.
     */
    public function test_approved_leave_does_not_count_against_anybody(): void
    {
        $this->days('on_time', 90);
        $this->days('on_leave', 20, '2026-05-04');

        $result = $this->measure();

        $this->assertSame(90, $result['expected'], 'Leave was counted as a day they were expected.');
        $this->assertEqualsWithDelta(100.0, $result['rate'], 0.01);
        $this->assertSame(20, $result['on_leave'], 'The leave should still be visible.');
    }

    public function test_weekends_and_holidays_are_not_expected_days(): void
    {
        $this->days('on_time', 50);
        $this->days('weekend', 20, '2026-04-06');
        $this->days('holiday', 4, '2026-05-04');

        $this->assertSame(50, $this->measure()['expected']);
    }

    /**
     * A missing clock-in is a gap in the record, not proof somebody stayed
     * home. Counting it against them accuses them on the strength of data
     * that is not there.
     */
    public function test_a_missing_clock_in_is_not_treated_as_an_absence(): void
    {
        $this->days('on_time', 90);
        $this->days('no_clock_in', 10, '2026-05-04');

        $result = $this->measure();

        $this->assertSame(0, $result['absent']);
        $this->assertSame(90, $result['expected']);
        $this->assertEqualsWithDelta(100.0, $result['rate'], 0.01);
        // Reported, so a reviewer can go and ask rather than never knowing.
        $this->assertSame(10, $result['unrecorded']);
    }

    /** Somebody who came and forgot to close the day was still there. */
    public function test_a_missing_clock_out_still_counts_as_present(): void
    {
        $this->days('on_time', 50);
        $this->days('no_clock_out', 10, '2026-04-06');

        $result = $this->measure();

        $this->assertSame(60, $result['present']);
        $this->assertSame(0, $result['absent']);
    }

    public function test_lateness_is_present_but_counted_separately(): void
    {
        $this->days('on_time', 40);
        $this->days('late', 10, '2026-04-06');

        $result = $this->measure();

        $this->assertSame(50, $result['present'], 'A late arrival is still an arrival.');
        $this->assertSame(10, $result['late']);
    }

    /**
     * An empty set has no rate. 0% says somebody never turned up and 100% says
     * they never missed a day; neither is supported by no data at all.
     */
    public function test_no_recorded_days_gives_no_rate_rather_than_nought(): void
    {
        $result = $this->measure();

        $this->assertNull($result['rate']);
        $this->assertSame(0, $result['expected']);
        $this->assertNotNull($result['note']);
    }

    /** A cycle running to December, measured in June, covers six months. */
    public function test_the_window_stops_at_today_rather_than_the_cycle_end(): void
    {
        $future = PerformanceCycle::create([
            'organization_id' => $this->org->id, 'name' => 'FY 2026',
            'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_open' => true,
        ]);

        $result = (new CycleAttendance())->for($this->employee->id, $future);

        $this->assertSame('2026-06-30', $result['to'], 'The window ran past today.');
    }

    public function test_a_cycle_that_has_not_started_has_no_attendance(): void
    {
        $next = PerformanceCycle::create([
            'organization_id' => $this->org->id, 'name' => 'H1 2027',
            'starts_on' => '2027-01-01', 'ends_on' => '2027-06-30', 'is_open' => true,
        ]);

        $result = (new CycleAttendance())->for($this->employee->id, $next);

        $this->assertNull($result['rate']);
        $this->assertStringContainsString('not started', $result['note']);
    }

    public function test_days_outside_the_cycle_are_not_counted(): void
    {
        $this->days('on_time', 10);
        $this->days('absent', 10, '2025-11-03');   // the cycle before

        $result = $this->measure();

        $this->assertSame(10, $result['expected']);
        $this->assertSame(0, $result['absent']);
    }
}
