<?php

namespace Tests\Feature\HR;

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
 * The figures along the top of the employee directory.
 *
 * The failure being guarded against is a headline counted over a different
 * population from the rows beneath it — the number somebody quotes in a
 * meeting and then cannot reconcile against the table they are looking at.
 */
class DirectoryFiguresTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Organization $other;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-11 09:00:00'));

        $this->org   = Organization::create(['name' => 'Konvergenz', 'slug' => 'konvergenz']);
        $this->other = Organization::create(['name' => 'Tiberbu', 'slug' => 'tiberbu']);
        app()->instance('current.organization_id', $this->org->id);

        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'HR', 'scope' => 'organization',
            'permissions' => [Permission::EMPLOYEES_VIEW],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(array $attrs = [], ?Organization $org = null): Employee
    {
        return Employee::create($attrs + [
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => ($org ?? $this->org)->id,
            'first_name' => 'A', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'status' => 'active',
        ]);
    }

    private function figures(): array
    {
        return $this->getJson("/api/v1/employees/summary?org={$this->org->slug}")
            ->assertOk()->json('data');
    }

    public function test_it_counts_the_organisation_not_the_database(): void
    {
        $this->employee();
        $this->employee();
        $this->employee(org: $this->other);

        $this->assertSame(2, $this->figures()['total']);
    }

    /**
     * A record halfway through the onboarding wizard is not somebody who works
     * here, and the listing already hides it. Counting it makes the headline
     * disagree with the table.
     */
    public function test_drafts_are_excluded_the_way_the_listing_excludes_them(): void
    {
        $this->employee();
        $this->employee(['status' => 'draft']);

        $this->assertSame(1, $this->figures()['total']);
    }

    public function test_exited_people_are_in_the_total_but_not_in_active(): void
    {
        $this->employee();
        $this->employee(['status' => 'exited', 'date_exited' => '2026-01-01']);

        $f = $this->figures();

        $this->assertSame(2, $f['total']);
        $this->assertSame(1, $f['active']);
    }

    public function test_new_this_month_counts_hires_since_the_first_of_the_month(): void
    {
        $this->employee(['date_hired' => '2026-03-02']);
        $this->employee(['date_hired' => '2026-02-27']);

        $this->assertSame(1, $this->figures()['new_this_month']);
    }

    /** A start date in the future is not somebody who has joined. */
    public function test_a_future_start_date_is_not_a_new_joiner_yet(): void
    {
        $this->employee(['date_hired' => '2026-03-30']);

        $this->assertSame(0, $this->figures()['new_this_month']);
    }

    // ── On leave ──────────────────────────────────────────────────────────

    private function leave(Employee $who, string $status, string $from, string $to): void
    {
        $type = LeaveType::firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => 'AL'],
            ['name' => 'Annual Leave', 'days_per_year' => 21, 'counting_method' => 'business_days'],
        );

        LeaveRequest::create([
            'organization_id' => $this->org->id,
            'employee_id'     => $who->id,
            'leave_type_id'   => $type->id,
            'from_date'       => $from, 'to_date' => $to,
            'days_requested'  => 1, 'reason' => 'Away', 'status' => $status,
        ]);
    }

    public function test_on_leave_counts_people_approved_and_away_today(): void
    {
        $away = $this->employee();
        $this->employee();

        $this->leave($away, 'approved', '2026-03-10', '2026-03-12');

        $this->assertSame(1, $this->figures()['on_leave']);
    }

    /** Asked for is not granted — the leave screens count it the same way. */
    public function test_a_pending_request_does_not_make_somebody_away(): void
    {
        $this->leave($this->employee(), 'pending', '2026-03-10', '2026-03-12');

        $this->assertSame(0, $this->figures()['on_leave']);
    }

    public function test_two_approved_requests_covering_today_count_the_person_once(): void
    {
        $away = $this->employee();

        $this->leave($away, 'approved', '2026-03-10', '2026-03-12');
        $this->leave($away, 'approved', '2026-03-11', '2026-03-11');

        $this->assertSame(1, $this->figures()['on_leave']);
    }

    /**
     * The tab must be a strict subset of the listing without it, or the figure
     * and the rows describe different people.
     */
    public function test_the_on_leave_filter_returns_exactly_the_people_the_figure_counted(): void
    {
        $away = $this->employee(['first_name' => 'Away']);
        $this->employee(['first_name' => 'Here']);

        $this->leave($away, 'approved', '2026-03-10', '2026-03-12');

        $rows = $this->getJson("/api/v1/employees?on_leave=1&org={$this->org->slug}")
            ->assertOk()->json('data');

        $this->assertCount($this->figures()['on_leave'], $rows);
        $this->assertSame($away->id, $rows[0]['id']);
    }
}
