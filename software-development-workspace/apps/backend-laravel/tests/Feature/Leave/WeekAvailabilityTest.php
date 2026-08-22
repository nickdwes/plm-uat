<?php

namespace Tests\Feature\Leave;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who is about this week.
 *
 * The question a team leader has on a Monday morning, which the requests table
 * cannot answer directly: it lists what was asked for, not who is in.
 */
class WeekAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private LeaveType $type;
    private Employee $boss;
    private Employee $report;

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed Wednesday, so "this week" is the same week every run.
        Carbon::setTestNow(Carbon::parse('2026-03-11 09:00:00'));

        $this->org = Organization::create(['name' => 'Konvergenz', 'slug' => 'konvergenz']);
        app()->instance('current.organization_id', $this->org->id);

        $this->type = LeaveType::create([
            'organization_id' => $this->org->id,
            'name' => 'Annual Leave', 'code' => 'AL',
            'days_per_year' => 21, 'counting_method' => 'business_days',
        ]);

        $this->boss   = $this->employee('Angie', 'Mumo');
        $this->report = $this->employee('James', 'Kinyanjui', $this->boss->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(string $first, string $last, ?int $reportsTo = null): Employee
    {
        return Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => $first, 'last_name' => $last,
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'status' => 'active', 'reports_to' => $reportsTo,
        ]);
    }

    private function signIn(Employee $as, array $permissions): void
    {
        $user = User::factory()->create();
        $as->update(['user_id' => $user->id]);

        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Leave', 'scope' => 'organization',
            'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    private function leave(Employee $who, array $attrs): LeaveRequest
    {
        return LeaveRequest::create($attrs + [
            'organization_id' => $this->org->id,
            'employee_id'     => $who->id,
            'leave_type_id'   => $this->type->id,
            'days_requested'  => 1,
            'reason'          => 'Away',
            'status'          => 'approved',
        ]);
    }

    private function get_(string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->getJson("/api/v1/leave/availability?org={$this->org->slug}" . ($query ? "&{$query}" : ''));
    }

    private function personFor(array $data, int $employeeId): array
    {
        return collect($data['people'])->firstWhere('employee_id', $employeeId) ?? [];
    }

    // ── Everybody, not only the absent ────────────────────────────────────

    /**
     * Starting from the leave rows lists only the people who are away. The
     * useful list is everybody, most of whom are in.
     */
    public function test_it_lists_people_who_are_available_not_only_those_on_leave(): void
    {
        $this->signIn($this->boss, [Permission::LEAVE_VIEW, Permission::LEAVE_MANAGE]);

        $data = $this->get_()->assertOk()->json('data');

        $this->assertCount(2, $data['people']);
        $this->assertSame('available', $this->personFor($data, $this->report->id)['status']);
    }

    public function test_the_week_runs_monday_to_sunday_regardless_of_the_day_you_open_it(): void
    {
        $this->signIn($this->boss, [Permission::LEAVE_VIEW, Permission::LEAVE_MANAGE]);

        $data = $this->get_()->assertOk()->json('data');

        $this->assertSame('2026-03-09', $data['week_start'], 'Monday of the fixed Wednesday.');
        $this->assertSame('2026-03-15', $data['week_end']);
    }

    public function test_approved_leave_names_the_days_it_covers(): void
    {
        $this->signIn($this->boss, [Permission::LEAVE_VIEW, Permission::LEAVE_MANAGE]);

        // Wednesday to Friday of the fixed week.
        $this->leave($this->report, ['from_date' => '2026-03-11', 'to_date' => '2026-03-13', 'days_requested' => 3]);

        $person = $this->personFor($this->get_()->assertOk()->json('data'), $this->report->id);

        $this->assertSame('on_leave', $person['status']);
        $this->assertSame(['Wed', 'Thu', 'Fri'], $person['days']);
    }

    /**
     * Someone taking Monday and Thursday is present on the Tuesday. Rendering
     * a scattered request as a span tells their manager they are away for four
     * days when they are away for two.
     */
    public function test_scattered_leave_lists_only_the_days_actually_taken(): void
    {
        $this->signIn($this->boss, [Permission::LEAVE_VIEW, Permission::LEAVE_MANAGE]);

        $this->leave($this->report, [
            'from_date' => '2026-03-09', 'to_date' => '2026-03-12',
            'days_requested' => 2,
            'scattered_dates' => ['2026-03-09', '2026-03-12'],
        ]);

        $person = $this->personFor($this->get_()->assertOk()->json('data'), $this->report->id);

        $this->assertSame(['Mon', 'Thu'], $person['days']);
    }

    /** Leave that starts before the week is clipped to it, not spilled across it. */
    public function test_leave_running_into_the_week_is_clipped_to_the_week(): void
    {
        $this->signIn($this->boss, [Permission::LEAVE_VIEW, Permission::LEAVE_MANAGE]);

        $this->leave($this->report, ['from_date' => '2026-03-04', 'to_date' => '2026-03-10', 'days_requested' => 7]);

        $person = $this->personFor($this->get_()->assertOk()->json('data'), $this->report->id);

        $this->assertSame(['Mon', 'Tue'], $person['days']);
    }

    public function test_a_pending_request_is_shown_as_pending_not_as_away(): void
    {
        $this->signIn($this->boss, [Permission::LEAVE_VIEW, Permission::LEAVE_MANAGE]);

        $this->leave($this->report, [
            'from_date' => '2026-03-12', 'to_date' => '2026-03-12', 'status' => 'pending',
        ]);

        $person = $this->personFor($this->get_()->assertOk()->json('data'), $this->report->id);

        $this->assertSame('pending', $person['status']);
    }

    public function test_leave_in_another_week_does_not_appear(): void
    {
        $this->signIn($this->boss, [Permission::LEAVE_VIEW, Permission::LEAVE_MANAGE]);

        $this->leave($this->report, ['from_date' => '2026-03-23', 'to_date' => '2026-03-24', 'days_requested' => 2]);

        $person = $this->personFor($this->get_()->assertOk()->json('data'), $this->report->id);

        $this->assertSame('available', $person['status']);
        $this->assertSame([], $person['days']);
    }

    // ── Scope ─────────────────────────────────────────────────────────────

    public function test_a_manager_sees_only_their_own_team(): void
    {
        $stranger = $this->employee('Sara', 'Thompson');

        $this->signIn($this->boss, [Permission::LEAVE_VIEW_TEAM, Permission::LEAVE_APPROVE]);

        $ids = array_column($this->get_()->assertOk()->json('data.people'), 'employee_id');

        $this->assertContains($this->report->id, $ids);
        $this->assertNotContains($stranger->id, $ids);
    }

    /** A one-row table telling you that you are available is not a screen. */
    public function test_a_plain_employee_is_refused(): void
    {
        $this->signIn($this->report, [Permission::LEAVE_VIEW_OWN]);

        $this->get_()->assertForbidden();
    }
}
