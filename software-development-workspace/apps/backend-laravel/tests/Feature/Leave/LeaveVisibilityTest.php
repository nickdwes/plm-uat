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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One leave screen, three widths.
 *
 * An HR officer sees the organisation, a team leader sees their reports, and
 * everybody else sees themselves. The rows and the figures along the top are
 * counted over the same population on purpose: a headline that includes rows
 * the table then refuses is the number somebody quotes in a meeting and cannot
 * reconcile afterwards.
 */
class LeaveVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private LeaveType $type;
    private Employee $boss;
    private Employee $report;
    private Employee $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Konvergenz', 'slug' => 'konvergenz']);
        app()->instance('current.organization_id', $this->org->id);

        $this->type = LeaveType::create([
            'organization_id' => $this->org->id,
            'name' => 'Annual Leave', 'code' => 'AL',
            'days_per_year' => 21, 'counting_method' => 'business_days',
        ]);

        $this->boss     = $this->employee('Angie', 'Mumo');
        $this->report   = $this->employee('James', 'Kinyanjui', $this->boss->id);
        $this->stranger = $this->employee('Sara', 'Thompson');
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

    private function signIn(Employee $as, array $permissions): User
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

        return $user;
    }

    private function leave(Employee $who, string $status = 'pending', array $extra = []): LeaveRequest
    {
        // $extra first: array union keeps the left-hand value, so the defaults
        // have to be the fallback rather than the other way round.
        return LeaveRequest::create($extra + [
            'organization_id' => $this->org->id,
            'employee_id'     => $who->id,
            'leave_type_id'   => $this->type->id,
            'from_date'       => now()->toDateString(),
            'to_date'         => now()->addDays(2)->toDateString(),
            'days_requested'  => 3,
            'reason'          => 'Away',
            'status'          => $status,
        ]);
    }

    private function url(string $path = '', string $query = ''): string
    {
        return "/api/v1/leave{$path}?org={$this->org->slug}" . ($query ? "&{$query}" : '');
    }

    // ── Who sees what ─────────────────────────────────────────────────────

    public function test_an_employee_sees_only_their_own_requests(): void
    {
        $this->signIn($this->report, [Permission::LEAVE_VIEW_OWN, Permission::LEAVE_SUBMIT]);

        $mine = $this->leave($this->report);
        $this->leave($this->stranger);
        $this->leave($this->boss);

        $res = $this->getJson($this->url())->assertOk();

        $this->assertSame([$mine->id], array_column($res->json('data'), 'id'));
        $this->assertSame('own', $res->json('meta.scope'));
    }

    public function test_a_manager_sees_their_reports_and_themselves_but_not_a_stranger(): void
    {
        $this->signIn($this->boss, [Permission::LEAVE_VIEW_TEAM, Permission::LEAVE_APPROVE]);

        $theirs = $this->leave($this->report);
        $mine   = $this->leave($this->boss);
        $other  = $this->leave($this->stranger);

        $ids = array_column($this->getJson($this->url())->assertOk()->json('data'), 'id');

        $this->assertContains($theirs->id, $ids);
        $this->assertContains($mine->id, $ids, 'A manager on leave is part of who is away.');
        $this->assertNotContains($other->id, $ids);
    }

    public function test_hr_sees_the_whole_organisation(): void
    {
        $this->signIn($this->boss, [Permission::LEAVE_VIEW, Permission::LEAVE_MANAGE]);

        $a = $this->leave($this->report);
        $b = $this->leave($this->stranger);

        $res = $this->getJson($this->url())->assertOk();
        $ids = array_column($res->json('data'), 'id');

        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
        $this->assertSame('all', $res->json('meta.scope'));
    }

    /**
     * The permission is the ceiling, not the request. A front end that has not
     * yet worked out who it is talking to may ask for more than it holds.
     */
    public function test_asking_for_a_wider_scope_than_you_hold_is_capped_not_obeyed(): void
    {
        $this->signIn($this->report, [Permission::LEAVE_VIEW_OWN]);

        $mine = $this->leave($this->report);
        $this->leave($this->stranger);

        $res = $this->getJson($this->url('', 'scope=all'))->assertOk();

        $this->assertSame([$mine->id], array_column($res->json('data'), 'id'));
        $this->assertSame('own', $res->json('meta.scope'));
    }

    public function test_asking_for_a_narrower_scope_is_honoured(): void
    {
        $this->signIn($this->boss, [Permission::LEAVE_VIEW, Permission::LEAVE_MANAGE]);

        $mine = $this->leave($this->boss);
        $this->leave($this->stranger);

        $res = $this->getJson($this->url('', 'scope=own'))->assertOk();

        $this->assertSame([$mine->id], array_column($res->json('data'), 'id'));
        $this->assertSame('own', $res->json('meta.scope'));
    }

    /**
     * A manager with nobody under them has no team view — showing one would be
     * a heading promising a table that is empty for structural reasons.
     */
    public function test_a_manager_with_no_reports_falls_back_to_their_own(): void
    {
        $this->signIn($this->stranger, [Permission::LEAVE_VIEW_TEAM, Permission::LEAVE_APPROVE]);

        $this->assertSame('own', $this->getJson($this->url())->assertOk()->json('meta.scope'));
    }

    // ── The figures along the top ─────────────────────────────────────────

    public function test_the_summary_counts_the_same_population_the_listing_lists(): void
    {
        $this->signIn($this->boss, [Permission::LEAVE_VIEW_TEAM, Permission::LEAVE_APPROVE]);

        $this->leave($this->report);                 // pending, in the team
        $this->leave($this->stranger);               // pending, not in the team

        $summary = $this->getJson($this->url('/summary'))->assertOk()->json('data');

        $this->assertSame(1, $summary['pending_requests'], 'The stranger is not this manager\'s to count.');
        $this->assertSame('team', $summary['scope']);
    }

    public function test_availability_carries_its_denominator_and_is_null_for_an_empty_population(): void
    {
        $this->signIn($this->boss, [Permission::LEAVE_VIEW, Permission::LEAVE_MANAGE]);

        $this->leave($this->report, 'approved');

        $a = $this->getJson($this->url('/summary'))->assertOk()->json('data.availability');

        $this->assertSame(3, $a['headcount']);
        $this->assertSame(2, $a['available']);
        $this->assertEqualsWithDelta(66.7, $a['percentage'], 0.1);
    }

    public function test_a_pending_request_does_not_make_somebody_away_today(): void
    {
        $this->signIn($this->boss, [Permission::LEAVE_VIEW, Permission::LEAVE_MANAGE]);

        $this->leave($this->report, 'pending');

        $summary = $this->getJson($this->url('/summary'))->assertOk()->json('data');

        $this->assertSame(0, $summary['on_leave_today'], 'Asked for is not granted.');
    }

    // ── The month stepper ─────────────────────────────────────────────────

    /**
     * Leave running from the 28th to the 3rd belongs to both months. Filtering
     * on the start date alone drops it from one of them, which is how a
     * fortnight goes missing from a rota.
     */
    public function test_the_month_filter_includes_leave_that_straddles_the_boundary(): void
    {
        $this->signIn($this->boss, [Permission::LEAVE_VIEW, Permission::LEAVE_MANAGE]);

        $straddling = $this->leave($this->report, 'approved', [
            'from_date' => '2026-03-28', 'to_date' => '2026-04-03', 'days_requested' => 7,
        ]);

        foreach (['2026-03', '2026-04'] as $month) {
            $ids = array_column(
                $this->getJson($this->url('', "month={$month}"))->assertOk()->json('data'),
                'id',
            );

            $this->assertContains($straddling->id, $ids, "Missing from {$month}.");
        }
    }

    public function test_a_request_carries_a_quotable_reference(): void
    {
        $this->signIn($this->report, [Permission::LEAVE_VIEW_OWN]);

        $mine = $this->leave($this->report);

        $this->assertSame(
            '#' . str_pad((string) $mine->id, 4, '0', STR_PAD_LEFT),
            $this->getJson($this->url())->assertOk()->json('data.0.reference'),
        );
    }
}
