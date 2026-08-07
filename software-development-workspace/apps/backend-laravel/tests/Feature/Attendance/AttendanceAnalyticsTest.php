<?php

namespace Tests\Feature\Attendance;

use App\Models\Account\Account;
use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendancePolicy;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\Task\Task;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What the workforce actually did.
 *
 * Two things are proved here beyond the arithmetic. That rates are used
 * wherever people are compared, because a table sorted by raw totals quietly
 * ranks people by how much they were at work. And that the scope holds — a
 * report is exactly the place where somebody's whole year leaks sideways.
 */
class AttendanceAnalyticsTest extends TestCase
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

    private function day(Employee $e, string $date, array $attrs = []): AttendanceDay
    {
        return AttendanceDay::create($attrs + [
            'organization_id' => $this->org->id,
            'employee_id'     => $e->id,
            'work_date'       => $date,
        ]);
    }

    private function commit(AttendanceDay $day, array $attrs = []): void
    {
        $day->commitments()->create($attrs + [
            'organization_id' => $this->org->id,
            'title'           => 'Send the proposal',
        ]);
    }

    private function url(string $path, array $query = []): string
    {
        $q = http_build_query($query + [
            'org'  => $this->org->slug,
            'from' => now()->subWeeks(2)->toDateString(),
            'to'   => now()->toDateString(),
        ]);

        return "/api/v1/attendance/analytics{$path}?{$q}";
    }

    private function yesterday(): string
    {
        return now()->subDay()->toDateString();
    }

    // ── A row per person ──────────────────────────────────────────────────

    public function test_a_row_carries_attendance_location_and_work(): void
    {
        $me = $this->signIn($this->employee('Solo'), [Permission::ATTENDANCE_VIEW]);

        $day = $this->day($me, $this->yesterday(), [
            'status' => 'on_time', 'actual_location' => 'home', 'worked_minutes' => 480,
        ]);
        $this->commit($day, ['outcome' => 'done']);
        $this->commit($day, ['outcome' => 'not_done', 'reason' => 'Blocked on the client.']);
        $this->commit($day);   // nobody said

        $this->getJson($this->url('/people'))
            ->assertOk()
            ->assertJsonPath('data.0.employee.name', 'Solo Person')
            ->assertJsonPath('data.0.attendance.worked', 1)
            ->assertJsonPath('data.0.attendance.hours', 8)
            ->assertJsonPath('data.0.location.home', 1)
            ->assertJsonPath('data.0.work.promised', 3)
            ->assertJsonPath('data.0.work.done', 1)
            // Only the one nobody said anything about.
            ->assertJsonPath('data.0.work.unexplained', 1);
    }

    /**
     * Somebody on leave half the period has fewer of everything, and a table
     * sorted by raw totals quietly ranks people by how much they were at work.
     */
    public function test_punctuality_and_completion_are_rates_not_counts(): void
    {
        $me = $this->signIn($this->employee('Solo'), [Permission::ATTENDANCE_VIEW]);

        $this->day($me, now()->subDays(3)->toDateString(), ['status' => 'on_time']);
        $this->day($me, now()->subDays(2)->toDateString(), ['status' => 'on_time']);
        $this->day($me, now()->subDays(4)->toDateString(), ['status' => 'late']);

        $day = $this->day($me, $this->yesterday(), ['status' => 'on_time']);
        $this->commit($day, ['outcome' => 'done']);
        $this->commit($day, ['outcome' => 'not_done', 'reason' => 'Ran out of day.']);

        $this->getJson($this->url('/people'))
            ->assertOk()
            // Three of four on time.
            ->assertJsonPath('data.0.attendance.punctuality', 75)
            ->assertJsonPath('data.0.work.completion', 50);
    }

    /** Nothing to divide by is not zero per cent. */
    public function test_a_rate_with_nothing_behind_it_is_absent_rather_than_zero(): void
    {
        $me = $this->signIn($this->employee('Solo'), [Permission::ATTENDANCE_VIEW]);
        $this->day($me, $this->yesterday(), ['status' => 'on_leave']);

        $this->getJson($this->url('/people'))
            ->assertOk()
            ->assertJsonPath('data.0.attendance.punctuality', null)
            ->assertJsonPath('data.0.work.completion', null);
    }

    /** A report people have to scan for the problem is one they stop opening. */
    public function test_the_table_leads_with_whoever_has_the_most_unexplained(): void
    {
        $me = $this->signIn($this->employee('Aaa'), [Permission::ATTENDANCE_VIEW]);
        $other = $this->employee('Zzz');

        $mine = $this->day($me, $this->yesterday(), ['status' => 'on_time']);
        $this->commit($mine, ['outcome' => 'done']);

        $theirs = $this->day($other, $this->yesterday(), ['status' => 'on_time']);
        $this->commit($theirs);
        $this->commit($theirs);

        $this->getJson($this->url('/people'))
            ->assertOk()
            ->assertJsonPath('data.0.employee.name', 'Zzz Person')
            ->assertJsonPath('data.0.work.unexplained', 2);
    }

    /** Today is still running; counting it would make the figure meaningless. */
    public function test_todays_open_commitments_are_not_counted_as_unexplained(): void
    {
        $me = $this->signIn($this->employee('Solo'), [Permission::ATTENDANCE_VIEW]);

        $this->commit($this->day($me, now()->toDateString(), ['status' => 'on_time']));

        $this->getJson($this->url('/people'))
            ->assertOk()
            ->assertJsonPath('data.0.work.unexplained', 0);
    }

    // ── Scope ─────────────────────────────────────────────────────────────

    /** A report is exactly where somebody's whole year leaks sideways. */
    public function test_somebody_with_only_their_own_scope_sees_only_themselves(): void
    {
        $me = $this->signIn($this->employee('Mine'), [Permission::ATTENDANCE_VIEW_OWN]);
        $other = $this->employee('Theirs');

        $this->day($me, $this->yesterday(), ['status' => 'on_time']);
        $this->day($other, $this->yesterday(), ['status' => 'late']);

        $this->getJson($this->url('/people'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.name', 'Mine Person');
    }

    public function test_a_manager_sees_themselves_and_their_reports(): void
    {
        $manager = $this->employee('Manager');
        $report  = $this->employee('Report', reportsTo: $manager->id);
        $outside = $this->employee('Outside');

        foreach ([$manager, $report, $outside] as $e) {
            $this->day($e, $this->yesterday(), ['status' => 'on_time']);
        }

        $this->signIn($manager, [Permission::ATTENDANCE_VIEW_TEAM]);

        $names = collect($this->getJson($this->url('/people'))->assertOk()->json('data'))
            ->pluck('employee.name')->sort()->values()->all();

        $this->assertSame(['Manager Person', 'Report Person'], $names);
    }

    // ── Trends ────────────────────────────────────────────────────────────

    public function test_location_is_reported_week_by_week(): void
    {
        $me = $this->signIn($this->employee('Solo'), [Permission::ATTENDANCE_VIEW]);

        $this->day($me, $this->yesterday(), ['actual_location' => 'office']);
        $this->day($me, now()->subDays(2)->toDateString(), ['actual_location' => 'home']);

        $response = $this->getJson($this->url('/trends'))->assertOk();

        $this->assertGreaterThanOrEqual(1, count($response->json('weeks')));
        $this->assertSame(2, collect($response->json('weeks'))->sum('total'));
    }

    /**
     * "Said office, worked from home" tells you whether the location reporting
     * is worth anything — a plan nobody keeps should not drive desk planning.
     */
    public function test_plan_adherence_compares_what_was_said_with_what_happened(): void
    {
        $me = $this->signIn($this->employee('Solo'), [Permission::ATTENDANCE_VIEW]);

        $this->day($me, $this->yesterday(), [
            'planned_location' => 'office', 'actual_location' => 'office',
        ]);
        $this->day($me, now()->subDays(2)->toDateString(), [
            'planned_location' => 'office', 'actual_location' => 'home',
        ]);

        $this->getJson($this->url('/trends'))
            ->assertOk()
            ->assertJsonPath('plan_adherence.stated', 2)
            ->assertJsonPath('plan_adherence.kept', 1)
            ->assertJsonPath('plan_adherence.rate', 50);
    }

    // ── Where the work was pointed ────────────────────────────────────────

    public function test_committed_work_is_grouped_by_project_and_customer(): void
    {
        $me = $this->signIn($this->employee('Solo'), [Permission::ATTENDANCE_VIEW]);

        $project = Project::create(['organization_id' => $this->org->id, 'name' => 'Portal Revamp']);
        $account = Account::create(['organization_id' => $this->org->id, 'name' => 'Safaricom']);

        $task = Task::create([
            'organization_id' => $this->org->id, 'title' => 'Cut over the firewall',
            'project_id' => $project->id, 'account_id' => $account->id,
        ]);

        $day = $this->day($me, $this->yesterday(), ['status' => 'on_time']);
        $this->commit($day, ['task_id' => $task->id]);

        $this->getJson($this->url('/distribution'))
            ->assertOk()
            ->assertJsonPath('by_project.0.label', 'Portal Revamp')
            ->assertJsonPath('by_account.0.label', 'Safaricom');
    }

    /**
     * An approved enablement day with nothing recorded against it is the answer
     * nobody wants but everybody needs — a day off by another name.
     */
    public function test_enablement_days_with_nothing_recorded_are_counted(): void
    {
        $me = $this->signIn($this->employee('Solo'), [Permission::ATTENDANCE_VIEW]);

        $used = $this->day($me, $this->yesterday(), [
            'is_enablement_day' => true, 'enablement_status' => 'approved',
        ]);
        $this->commit($used, ['kind' => 'enablement', 'outcome' => 'done']);

        $this->day($me, now()->subDays(2)->toDateString(), [
            'is_enablement_day' => true, 'enablement_status' => 'approved',
        ]);

        $this->getJson($this->url('/distribution'))
            ->assertOk()
            ->assertJsonPath('enablement.days', 2)
            ->assertJsonPath('enablement.done', 1)
            ->assertJsonPath('enablement.days_with_nothing_recorded', 1);
    }

    /** A declined day is not an enablement day and must not be counted as one. */
    public function test_declined_enablement_days_are_not_counted(): void
    {
        $me = $this->signIn($this->employee('Solo'), [Permission::ATTENDANCE_VIEW]);

        $this->day($me, $this->yesterday(), [
            'is_enablement_day' => false, 'enablement_status' => 'declined',
        ]);

        $this->getJson($this->url('/distribution'))->assertOk()->assertJsonPath('enablement.days', 0);
    }
}
