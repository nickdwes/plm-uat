<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Putting somebody into a second organisation for a while.
 *
 * Secondments used to exist twice: a free-text table holding an organisation
 * name, and the organisation map holding the same idea properly. The designs'
 * Scope of Engagement panel needs the second — a time allocation, whether the
 * time is billable, and an organisation picked from the tenants rather than
 * typed. "Who is 50% billable to Tiberbu this quarter" is the only reason
 * anybody records a secondment, and a free-text name cannot answer it.
 */
class SecondmentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $home;
    private Organization $host;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = Organization::create(['name' => 'Konvergenz', 'slug' => 'konvergenz']);
        $this->host = Organization::create(['name' => 'Tiberbu Healthnet', 'slug' => 'tiberbu']);
        app()->instance('current.organization_id', $this->home->id);

        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'HR', 'scope' => 'organization',
            'permissions' => [Permission::EMPLOYEES_VIEW, Permission::EMPLOYEES_UPDATE],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->home->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        $this->employee = Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->home->id,
            'first_name' => 'James', 'last_name' => 'Kinyanjui',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/employees/{$this->employee->id}/secondments{$path}?org={$this->home->slug}";
    }

    private function second(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->url(), $body + [
            'organization_id' => $this->host->id,
            'role' => 'Product Manager',
            'engagement_type' => 'secondment',
            'allocation_pct' => 50,
            'is_billable' => true,
            'effective_from' => now()->toDateString(),
        ]);
    }

    // ── Recording one ─────────────────────────────────────────────────────

    public function test_a_secondment_records_the_allocation_and_whether_it_is_billable(): void
    {
        $this->second()
            ->assertCreated()
            ->assertJsonPath('data.0.organization.name', 'Tiberbu Healthnet')
            ->assertJsonPath('data.0.allocation_pct', 50)
            ->assertJsonPath('data.0.is_billable', true)
            ->assertJsonPath('data.0.engagement_type', 'secondment');
    }

    /** The organisation is picked, not typed, so it can be counted. */
    public function test_the_organisation_must_be_one_that_exists(): void
    {
        $this->second(['organization_id' => 9999])
            ->assertJsonValidationErrorFor('organization_id');
    }

    public function test_somebody_cannot_be_seconded_to_their_own_organisation(): void
    {
        $this->second(['organization_id' => $this->home->id])->assertStatus(422);
    }

    /**
     * Somebody seconded at 60% and 60% is a promise the week cannot keep, and
     * noticing is the entire point of recording a percentage.
     */
    public function test_allocations_cannot_add_up_to_more_than_a_full_week(): void
    {
        $third = Organization::create(['name' => 'TEEDU Academy', 'slug' => 'teedu']);

        $this->second(['allocation_pct' => 60])->assertCreated();

        $this->second(['organization_id' => $third->id, 'allocation_pct' => 60])
            ->assertStatus(422);
    }

    public function test_allocations_that_fit_are_accepted(): void
    {
        $third = Organization::create(['name' => 'TEEDU Academy', 'slug' => 'teedu']);

        $this->second(['allocation_pct' => 50])->assertCreated();
        $this->second(['organization_id' => $third->id, 'allocation_pct' => 50])->assertCreated();
    }

    /** A secondment that is not happening is not a secondment. */
    public function test_a_zero_allocation_is_refused(): void
    {
        $this->second(['allocation_pct' => 0])->assertJsonValidationErrorFor('allocation_pct');
    }

    public function test_the_end_date_cannot_precede_the_start(): void
    {
        $this->second([
            'effective_from' => now()->toDateString(),
            'effective_to'   => now()->subWeek()->toDateString(),
        ])->assertJsonValidationErrorFor('effective_to');
    }

    // ── Ending one ────────────────────────────────────────────────────────

    /**
     * Marked inactive rather than deleted. Somebody was billable to that
     * organisation for three months, and payroll, reporting and anybody
     * reconciling an invoice still need to see it.
     */
    public function test_ending_a_secondment_keeps_the_record(): void
    {
        $this->second()->assertCreated();

        $this->deleteJson($this->url('/' . $this->host->id))
            ->assertOk()
            ->assertJsonPath('data.0.is_active', false)
            ->assertJsonCount(1, 'data');
    }

    /** Once it has ended, the week is free again. */
    public function test_an_ended_secondment_releases_its_allocation(): void
    {
        $third = Organization::create(['name' => 'TEEDU Academy', 'slug' => 'teedu']);

        $this->second(['allocation_pct' => 80])->assertCreated();
        $this->deleteJson($this->url('/' . $this->host->id))->assertOk();

        $this->second(['organization_id' => $third->id, 'allocation_pct' => 80])->assertCreated();
    }

    // ── What it is not ────────────────────────────────────────────────────

    /** Their primary organisation is not one of their secondments. */
    public function test_the_primary_organisation_is_not_listed_as_a_secondment(): void
    {
        // The map requires an effective date on every row, primary included.
        $this->employee->organizations()->attach($this->home->id, [
            'is_active' => true, 'access_scope' => 'organization',
            'effective_from' => now()->toDateString(), 'allocation_pct' => 100,
        ]);

        $this->second()->assertCreated()->assertJsonCount(1, 'data');
    }
}
