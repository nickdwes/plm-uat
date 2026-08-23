<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
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
 * Probation as a state somebody is actually in.
 *
 * `status` has always accepted it and onboarding has always written it. What
 * was missing was everything downstream: the queries that decide who works
 * here filtered on `active` alone, so a probationer was invisible to them —
 * and there was no way to end probation at all.
 */
class ProbationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));

        $this->org = Organization::create(['name' => 'Konvergenz', 'slug' => 'konvergenz']);
        app()->instance('current.organization_id', $this->org->id);

        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'HR', 'scope' => 'organization',
            'permissions' => [Permission::EMPLOYEES_VIEW, Permission::EMPLOYEES_UPDATE, Permission::LEAVE_MANAGE],
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

    private function employee(array $attrs = []): Employee
    {
        return Employee::create($attrs + [
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'New', 'last_name' => 'Starter',
            'employment_type' => 'full_time', 'date_hired' => '2026-05-01',
            'status' => 'active',
        ]);
    }

    // ── They work here ────────────────────────────────────────────────────

    /**
     * The scope that matters. Six statuses, and only draft and exited mean
     * somebody is not on the payroll.
     */
    public function test_employed_covers_every_state_except_draft_and_exited(): void
    {
        foreach (['active', 'probation', 'on_leave', 'suspended', 'draft', 'exited'] as $status) {
            $this->employee(['status' => $status]);
        }

        $this->assertSame(4, Employee::employed()->count());
    }

    /**
     * The worst version of the bug: a new hire on probation seeded no leave
     * balance, so they had no entitlement at all and only found out when they
     * asked for a day off.
     */
    public function test_somebody_on_probation_gets_a_leave_entitlement(): void
    {
        $probationer = $this->employee(['status' => 'probation']);

        LeaveType::create([
            'organization_id' => $this->org->id, 'name' => 'Annual Leave', 'code' => 'AL',
            'days_per_year' => 21, 'counting_method' => 'business_days', 'is_active' => true,
        ]);

        $this->postJson("/api/v1/leave-balances/init?org={$this->org->slug}", ['year' => 2026])
            ->assertOk();

        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $probationer->id,
            'entitled_days' => 21.0,
        ]);
    }

    // ── Counting them ─────────────────────────────────────────────────────

    public function test_the_directory_counts_probation_and_overdue_confirmation(): void
    {
        $this->employee(['status' => 'active']);
        $this->employee(['status' => 'probation', 'probation_end_date' => '2026-09-01']);
        $this->employee(['status' => 'probation', 'probation_end_date' => '2026-03-01']); // overdue
        $this->employee(['status' => 'probation']);                                       // no date set

        $figures = $this->getJson("/api/v1/employees/summary?org={$this->org->slug}")
            ->assertOk()->json('data');

        $this->assertSame(3, $figures['on_probation']);
        $this->assertSame(1, $figures['overdue_confirmation'], 'Only the one past its date.');
        $this->assertSame(1, $figures['active'], 'Probation is not active.');
        $this->assertSame(4, $figures['total']);
    }

    /**
     * Counted from the status, not the date. A date that has passed means
     * somebody is overdue confirmation, not that they have been confirmed.
     */
    public function test_a_passed_probation_date_does_not_confirm_anybody(): void
    {
        $person = $this->employee(['status' => 'probation', 'probation_end_date' => '2026-01-01']);

        $this->assertSame('probation', $person->fresh()->status);
        $this->assertSame(1, Employee::onProbation()->count());
    }

    // ── Ending it ─────────────────────────────────────────────────────────

    public function test_somebody_can_be_confirmed_off_probation(): void
    {
        $person = $this->employee(['status' => 'probation', 'probation_end_date' => '2026-06-01']);

        $this->postJson("/api/v1/employees/{$person->id}/status?org={$this->org->slug}", [
            'status' => 'active',
        ])->assertOk();

        $fresh = $person->fresh();

        $this->assertSame('active', $fresh->status);
        // Cleared, or they stay in the overdue-confirmation count forever.
        $this->assertNull($fresh->probation_end_date);
    }

    public function test_somebody_can_be_put_on_probation_with_an_end_date(): void
    {
        $person = $this->employee();

        $this->postJson("/api/v1/employees/{$person->id}/status?org={$this->org->slug}", [
            'status' => 'probation', 'probation_end_date' => '2026-12-01',
        ])->assertOk();

        $this->assertSame('2026-12-01', $person->fresh()->probation_end_date->toDateString());
    }

    /**
     * Exit needs a date and a reason. Letting it through a general status
     * setter would produce leavers with neither.
     */
    public function test_exit_is_not_reachable_through_the_status_endpoint(): void
    {
        $person = $this->employee();

        $this->postJson("/api/v1/employees/{$person->id}/status?org={$this->org->slug}", [
            'status' => 'exited',
        ])->assertStatus(422);

        $this->assertSame('active', $person->fresh()->status);
    }

    public function test_somebody_who_has_left_cannot_be_quietly_reinstated(): void
    {
        $person = $this->employee(['status' => 'exited', 'date_exited' => '2026-01-31']);

        $this->postJson("/api/v1/employees/{$person->id}/status?org={$this->org->slug}", [
            'status' => 'active',
        ])->assertStatus(422);
    }
}
