<?php

namespace Tests\Feature\Attendance;

use App\Models\Account\Account;
use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendancePolicy;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\PublicHoliday;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Attendance and where people are working.
 *
 * Nothing in the old PLM covers this, so there is no legacy to argue from — the
 * rules come from the requirement, and what is proved here is the handful that
 * decide whether anybody trusts the page.
 *
 * Chiefly: a day is not absent until it is over, a public holiday is never an
 * absence, and moving the office start time does not retroactively make last
 * year's punctual staff late.
 */
class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        AttendancePolicy::forOrganization($this->org->id);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function employee(string $first = 'Test', ?int $reportsTo = null): Employee
    {
        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => $first, 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'reports_to' => $reportsTo,
        ]);
    }

    private function signIn(Employee $employee, array $permissions): Employee
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        $employee->update(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    /** Somebody recording their own attendance and nobody else's. */
    private function staff(string $first = 'Test'): Employee
    {
        return $this->signIn($this->employee($first), [
            Permission::ATTENDANCE_VIEW_OWN, Permission::ATTENDANCE_RECORD,
        ]);
    }

    private function url(string $path = '', array $query = []): string
    {
        $q = http_build_query($query + ['org' => $this->org->slug]);

        return "/api/v1/attendance{$path}?{$q}";
    }

    /** A weekday well clear of month ends, so windows behave predictably. */
    private function aWorkday(): CarbonImmutable
    {
        $d = CarbonImmutable::parse('2026-03-11');   // a Wednesday

        return $d;
    }

    // ── The clock ─────────────────────────────────────────────────────────

    public function test_clocking_in_opens_the_day_and_records_where(): void
    {
        $this->staff();

        $this->postJson($this->url('/clock-in'), ['location_type' => 'home'])
            ->assertOk()
            ->assertJsonPath('data.actual_location', 'home')
            ->assertJsonPath('data.is_clocked_in', true);

        $this->assertSame(1, AttendanceDay::count());
    }

    /**
     * People press the button again when a page has not refreshed. Two open
     * punches is a day with no honest way to total it.
     */
    public function test_clocking_in_twice_is_the_same_clock_in(): void
    {
        $this->staff();

        $this->postJson($this->url('/clock-in'))->assertOk();
        $this->postJson($this->url('/clock-in'))->assertOk();

        $this->assertSame(1, AttendanceDay::first()->punches()->count());
    }

    public function test_clocking_out_with_nothing_open_says_so(): void
    {
        $this->staff();

        $this->postJson($this->url('/clock-out'))->assertStatus(422);
    }

    /**
     * Summed per punch, so a lunch break between two of them is not paid for
     * by counting first-in to last-out.
     */
    public function test_hours_are_summed_per_punch_not_end_to_end(): void
    {
        $employee = $this->staff();
        $date     = $this->aWorkday();

        $this->postJson($this->url('/add-hours'), [
            'work_date' => $date->toDateString(), 'from' => '08:00', 'to' => '12:00',
        ])->assertOk();

        $this->postJson($this->url('/add-hours'), [
            'work_date' => $date->toDateString(), 'from' => '14:00', 'to' => '17:00',
        ])->assertOk()
            // Seven, not nine — the two hours in between were not worked.
            ->assertJsonPath('data.worked_minutes', 420);
    }

    /** A typed figure and a clock reading are different evidence. */
    public function test_hours_entered_by_hand_are_marked_as_such(): void
    {
        $this->staff();

        $this->postJson($this->url('/add-hours'), [
            'work_date' => $this->aWorkday()->toDateString(), 'from' => '08:00', 'to' => '17:00',
        ])->assertOk()->assertJsonPath('data.punches.0.is_manual', true);

        $this->postJson($this->url('/clock-in'))->assertOk();

        $this->assertFalse(
            AttendanceDay::whereDate('work_date', now()->toDateString())->first()->punches->first()->is_manual,
        );
    }

    // ── What the day was ──────────────────────────────────────────────────

    public function test_arriving_within_the_grace_period_is_on_time(): void
    {
        $this->staff();
        $date = $this->aWorkday();

        // Policy starts at 08:00 with ten minutes' grace.
        $this->postJson($this->url('/add-hours'), [
            'work_date' => $date->toDateString(), 'from' => '08:07', 'to' => '17:00',
        ])->assertOk()->assertJsonPath('data.status', 'on_time');
    }

    public function test_arriving_after_the_grace_period_is_late(): void
    {
        $this->staff();

        $this->postJson($this->url('/add-hours'), [
            'work_date' => $this->aWorkday()->toDateString(), 'from' => '08:40', 'to' => '17:00',
        ])->assertOk()->assertJsonPath('data.status', 'late');
    }

    /**
     * The designs count "early clock in" apart from "on time", so it needs its
     * own threshold — otherwise every keen arrival is indistinguishable from a
     * normal one.
     */
    public function test_arriving_well_before_the_start_is_early(): void
    {
        $this->staff();

        $this->postJson($this->url('/add-hours'), [
            'work_date' => $this->aWorkday()->toDateString(), 'from' => '07:00', 'to' => '17:00',
        ])->assertOk()->assertJsonPath('data.status', 'early');
    }

    /**
     * A Thursday afternoon with nobody clocked in is a Thursday afternoon. A
     * red square on the calendar of somebody sitting at their desk is how
     * people learn to distrust the whole page.
     */
    public function test_a_day_is_not_absent_until_it_is_over(): void
    {
        /*
         * Pinned to a weekday. "Today" is only a working day five times a
         * week, and a test that passes on Wednesday and fails on Saturday
         * teaches people to rerun the suite rather than read it.
         */
        $this->travelTo($this->aWorkday()->setTime(14, 0));

        $this->staff();

        $this->postJson($this->url('/record'), [
            'work_date' => now()->toDateString(), 'planned_location' => 'office',
        ])->assertOk()->assertJsonPath('data.status', null);
    }

    public function test_a_past_workday_with_no_punches_is_absent(): void
    {
        $this->staff();

        $this->postJson($this->url('/record'), [
            'work_date' => $this->aWorkday()->toDateString(), 'planned_location' => 'office',
        ])->assertOk()->assertJsonPath('data.status', 'absent');
    }

    /** Checked before anything that could look like a failure. */
    public function test_a_public_holiday_is_never_an_absence(): void
    {
        $this->staff();
        $date = $this->aWorkday();

        PublicHoliday::create([
            'organization_id' => $this->org->id,
            'name' => 'Some holiday', 'date' => $date->toDateString(), 'year' => $date->year,
        ]);

        $this->postJson($this->url('/record'), [
            'work_date' => $date->toDateString(), 'planned_location' => 'office',
        ])->assertOk()->assertJsonPath('data.status', 'holiday');
    }

    public function test_approved_leave_is_never_an_absence(): void
    {
        $employee = $this->staff();
        $date     = $this->aWorkday();

        $type = LeaveType::create([
            'organization_id' => $this->org->id,
            'name' => 'Annual', 'code' => 'ANN', 'days_per_year' => 21,
        ]);

        LeaveRequest::create([
            'organization_id' => $this->org->id, 'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'from_date' => $date->subDay()->toDateString(),
            'to_date'   => $date->addDay()->toDateString(),
            'days_requested' => 3, 'status' => LeaveRequest::STATUS_APPROVED,
        ]);

        $this->postJson($this->url('/record'), [
            'work_date' => $date->toDateString(), 'planned_location' => 'office',
        ])->assertOk()->assertJsonPath('data.status', 'on_leave');
    }

    public function test_a_non_working_day_is_a_weekend(): void
    {
        $this->staff();

        $this->postJson($this->url('/record'), [
            'work_date' => '2026-03-14',   // a Saturday
            'planned_location' => 'home',
        ])->assertOk()->assertJsonPath('data.status', 'weekend');
    }

    /**
     * A missed button press is not the same failure as not turning up, and
     * counting them together would accuse people of absence.
     */
    public function test_clocking_in_and_never_out_is_its_own_status(): void
    {
        $employee = $this->staff();
        $date     = $this->aWorkday();

        $day = AttendanceDay::create([
            'organization_id' => $this->org->id, 'employee_id' => $employee->id,
            'work_date' => $date->toDateString(),
        ]);
        $day->punches()->create([
            'organization_id' => $this->org->id,
            'clocked_in_at' => $date->setTime(8, 0)->toDateTimeString(),
        ]);

        app(\App\Services\Attendance\AttendanceService::class)->recalculate($day->fresh('punches'));

        $this->assertSame('no_clock_out', $day->fresh()->status);
    }

    // ── Where the work happened ───────────────────────────────────────────

    /**
     * The gap between plan and actual is the single most useful thing a
     * hybrid-working report can say, and it is unrecoverable if only one of
     * the two is kept.
     */
    public function test_the_plan_and_what_happened_are_both_kept(): void
    {
        $this->staff();
        $date = now()->addDays(3)->toDateString();

        $this->postJson($this->url('/record'), [
            'work_date' => $date, 'planned_location' => 'office',
        ])->assertOk()->assertJsonPath('data.plan_was_kept', null);

        $this->postJson($this->url('/record'), [
            'work_date' => $date, 'actual_location' => 'home',
        ])->assertOk()
            ->assertJsonPath('data.planned_location', 'office')
            ->assertJsonPath('data.actual_location', 'home')
            ->assertJsonPath('data.plan_was_kept', false);
    }

    /** "Customer site engagement statistics" cannot count a free-text name. */
    public function test_a_customer_site_day_must_name_the_customer(): void
    {
        $this->staff();

        $this->postJson($this->url('/record'), [
            'work_date' => now()->toDateString(), 'planned_location' => 'customer_site',
        ])->assertJsonValidationErrorFor('account_id');

        $account = Account::create(['organization_id' => $this->org->id, 'name' => 'Safaricom']);

        $this->postJson($this->url('/record'), [
            'work_date' => now()->toDateString(),
            'planned_location' => 'customer_site', 'account_id' => $account->id,
        ])->assertOk()->assertJsonPath('data.account.name', 'Safaricom');
    }

    /** Policy, not code — the requirement makes advance reporting conditional. */
    public function test_planning_further_ahead_than_policy_allows_is_refused(): void
    {
        $this->staff();

        AttendancePolicy::forOrganization($this->org->id)->update(['plan_ahead_days' => 7]);

        $this->postJson($this->url('/record'), [
            'work_date' => now()->addDays(30)->toDateString(), 'planned_location' => 'home',
        ])->assertJsonValidationErrorFor('work_date');
    }

    // ── Changing the record ───────────────────────────────────────────────

    /**
     * Attendance is the record people have the strongest reason to revise
     * after the fact, which is exactly why the revisions have to be visible.
     */
    public function test_changing_a_location_is_written_to_the_audit_trail(): void
    {
        $this->staff();
        $date = $this->aWorkday()->toDateString();

        $this->postJson($this->url('/record'), ['work_date' => $date, 'actual_location' => 'office'])->assertOk();
        $this->postJson($this->url('/record'), ['work_date' => $date, 'actual_location' => 'home'])->assertOk();

        $day = AttendanceDay::firstOrFail();

        $this->getJson($this->url('/' . $day->id))
            ->assertOk()
            ->assertJsonPath('data.audits.0.field', 'actual_location')
            ->assertJsonPath('data.audits.0.from', 'office')
            ->assertJsonPath('data.audits.0.to', 'home');
    }

    /**
     * Moving the office start time must not retroactively make last year's
     * punctual staff late.
     */
    public function test_changing_the_policy_does_not_rewrite_past_days(): void
    {
        $this->signIn($this->employee(), [
            Permission::ATTENDANCE_VIEW_OWN, Permission::ATTENDANCE_RECORD, Permission::ATTENDANCE_MANAGE,
        ]);

        $this->postJson($this->url('/add-hours'), [
            'work_date' => $this->aWorkday()->toDateString(), 'from' => '08:05', 'to' => '17:00',
        ])->assertOk()->assertJsonPath('data.status', 'on_time');

        $this->putJson($this->url('/policy'), ['work_starts_at' => '07:00'])->assertOk();

        $this->assertSame('on_time', AttendanceDay::first()->status);
    }

    public function test_only_somebody_who_manages_attendance_can_set_the_working_day(): void
    {
        $this->staff();

        $this->putJson($this->url('/policy'), ['work_starts_at' => '07:00'])->assertForbidden();
    }

    // ── Who sees whose ────────────────────────────────────────────────────

    public function test_somebody_with_only_their_own_scope_sees_only_their_own(): void
    {
        $me = $this->staff('Mine');
        $other = $this->employee('Other');

        AttendanceDay::create([
            'organization_id' => $this->org->id, 'employee_id' => $other->id,
            'work_date' => now()->toDateString(), 'actual_location' => 'office',
        ]);
        $this->postJson($this->url('/record'), [
            'work_date' => now()->toDateString(), 'actual_location' => 'home',
        ])->assertOk();

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.name', 'Mine Person');
    }

    /** Their reports, and only theirs — not sideways, not their manager's. */
    public function test_a_manager_sees_their_own_reports(): void
    {
        $manager = $this->employee('Manager');
        $report  = $this->employee('Report', reportsTo: $manager->id);
        $someoneElse = $this->employee('Elsewhere');

        foreach ([$report, $someoneElse] as $e) {
            AttendanceDay::create([
                'organization_id' => $this->org->id, 'employee_id' => $e->id,
                'work_date' => now()->toDateString(), 'actual_location' => 'office',
            ]);
        }

        $this->signIn($manager, [Permission::ATTENDANCE_VIEW_TEAM]);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.name', 'Report Person');
    }

    public function test_asking_for_somebody_out_of_reach_is_refused(): void
    {
        $this->staff();
        $other = $this->employee('Other');

        $this->getJson($this->url('', ['employee_id' => $other->id]))->assertForbidden();
    }

    // ── The cards ─────────────────────────────────────────────────────────

    /**
     * Counted from the same rows the list shows, so the number on the card and
     * the length of the list can never tell different stories.
     */
    public function test_the_summary_counts_the_same_days_the_list_shows(): void
    {
        $me = $this->staff();
        $base = $this->aWorkday();

        // On time, late, and a day nobody turned up.
        $this->postJson($this->url('/add-hours'), [
            'work_date' => $base->toDateString(), 'from' => '08:05', 'to' => '17:00',
        ])->assertOk();
        $this->postJson($this->url('/add-hours'), [
            'work_date' => $base->addDay()->toDateString(), 'from' => '09:30', 'to' => '17:00',
        ])->assertOk();
        $this->postJson($this->url('/record'), [
            'work_date' => $base->addDays(2)->toDateString(), 'planned_location' => 'office',
        ])->assertOk();

        $window = ['from' => $base->startOfMonth()->toDateString(), 'to' => $base->endOfMonth()->toDateString()];

        $this->getJson($this->url('/summary', $window))
            ->assertOk()
            ->assertJsonPath('present.on_time', 1)
            ->assertJsonPath('present.late', 1)
            ->assertJsonPath('not_present.absent', 1);

        $this->getJson($this->url('', $window))->assertOk()->assertJsonCount(3, 'data');
    }

    /**
     * Not one of the designs' three cards, but it is the whole point of asking
     * people where they are working — a module that collects it and never
     * shows it teaches people the question does not matter.
     */
    public function test_the_summary_reports_where_the_work_happened(): void
    {
        $this->staff();
        $base = $this->aWorkday();

        $this->postJson($this->url('/record'), [
            'work_date' => $base->toDateString(), 'actual_location' => 'home',
        ])->assertOk();
        $this->postJson($this->url('/record'), [
            'work_date' => $base->addDay()->toDateString(), 'actual_location' => 'office',
        ])->assertOk();

        $this->getJson($this->url('/summary', [
            'from' => $base->startOfMonth()->toDateString(), 'to' => $base->endOfMonth()->toDateString(),
        ]))->assertOk()
            ->assertJsonPath('locations.home', 1)
            ->assertJsonPath('locations.office', 1)
            ->assertJsonPath('locations.customer_site', 0);
    }
}
