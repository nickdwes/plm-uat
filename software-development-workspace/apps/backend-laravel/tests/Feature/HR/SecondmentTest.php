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
            'permissions' => [Permission::EMPLOYEES_VIEW, Permission::EMPLOYEES_UPDATE, Permission::EMPLOYEES_CREATE],
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

    // ── Drafts ────────────────────────────────────────────────────────────

    /**
     * The wizard's Save as Draft.
     *
     * Status was hardcoded to active, so a half-filled record became a live
     * employee the moment the first step was saved — counted in headcount and
     * listed beside people who actually work here.
     */
    public function test_a_draft_employee_is_not_listed_as_an_employee(): void
    {
        $this->postJson("/api/v1/employees?org={$this->home->slug}", [
            'first_name' => 'Half', 'last_name' => 'Finished',
            'employment_type' => 'full_time', 'date_hired' => now()->toDateString(),
            'status' => 'draft',
        ])->assertSuccessful();

        $listed = collect($this->getJson("/api/v1/employees?org={$this->home->slug}")
            ->assertOk()->json('data'))->pluck('first_name');

        $this->assertNotContains('Half', $listed);

        // But findable when asked for by name, or the wizard could not resume.
        $drafts = collect($this->getJson("/api/v1/employees?org={$this->home->slug}&status=draft")
            ->assertOk()->json('data'))->pluck('first_name');

        $this->assertContains('Half', $drafts);
    }

    /** Anything that does not ask for a status still gets a live employee. */
    public function test_an_ordinary_create_is_still_active(): void
    {
        $this->postJson("/api/v1/employees?org={$this->home->slug}", [
            'first_name' => 'Fully', 'last_name' => 'Onboarded',
            'employment_type' => 'full_time', 'date_hired' => now()->toDateString(),
        ])->assertSuccessful();

        $this->assertSame('active', Employee::where('first_name', 'Fully')->firstOrFail()->status);
    }

    // ── Arriving as a list, from the wizard ───────────────────────────────

    /**
     * The onboarding wizard sends secondments as a list on create rather than
     * one call at a time, and that path had its own copy of the logic.
     */
    private function createWith(array $secondments): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/employees?org={$this->home->slug}", [
            'first_name' => 'Wanjiru', 'last_name' => 'Kamau',
            'employment_type' => 'full_time', 'date_hired' => now()->toDateString(),
            'secondments' => $secondments,
        ]);
    }

    /** It was validated on the way in and then never written. */
    public function test_the_engagement_type_is_actually_stored(): void
    {
        $this->createWith([[
            'org_id' => $this->host->id, 'engagement_type' => 'shared_service',
            'allocation_pct' => 40, 'start_date' => now()->toDateString(),
        ]])->assertSuccessful();

        $employee = Employee::where('first_name', 'Wanjiru')->firstOrFail();

        $this->assertSame(
            'shared_service',
            $employee->organizations()->first()->pivot->engagement_type,
        );
    }

    /**
     * The cap is refused one at a time, so it has to be refused in a list too —
     * otherwise the wizard is simply the way round it.
     */
    public function test_a_list_of_secondments_cannot_exceed_a_full_week(): void
    {
        $third = Organization::create(['name' => 'TEEDU Academy', 'slug' => 'teedu']);

        $this->createWith([
            ['org_id' => $this->host->id, 'allocation_pct' => 60, 'start_date' => now()->toDateString()],
            ['org_id' => $third->id,      'allocation_pct' => 60, 'start_date' => now()->toDateString()],
        ])->assertStatus(422);
    }

    public function test_a_list_that_fits_is_accepted(): void
    {
        $third = Organization::create(['name' => 'TEEDU Academy', 'slug' => 'teedu']);

        $this->createWith([
            ['org_id' => $this->host->id, 'allocation_pct' => 50, 'start_date' => now()->toDateString()],
            ['org_id' => $third->id,      'allocation_pct' => 50, 'start_date' => now()->toDateString()],
        ])->assertSuccessful();

        $this->assertCount(2, Employee::where('first_name', 'Wanjiru')->firstOrFail()->organizations);
    }

    /**
     * Editing somebody who has secondments filtered the pivot on a column that
     * does not exist, so every such edit failed at the database.
     */
    public function test_secondments_can_be_replaced_on_edit(): void
    {
        $third = Organization::create(['name' => 'TEEDU Academy', 'slug' => 'teedu']);

        $this->createWith([
            ['org_id' => $this->host->id, 'allocation_pct' => 50, 'start_date' => now()->toDateString()],
        ])->assertSuccessful();

        $employee = Employee::where('first_name', 'Wanjiru')->firstOrFail();

        $this->putJson("/api/v1/employees/{$employee->id}?org={$this->home->slug}", [
            'first_name' => 'Wanjiru', 'last_name' => 'Kamau',
            'employment_type' => 'full_time', 'date_hired' => now()->toDateString(),
            'secondments' => [
                ['org_id' => $third->id, 'allocation_pct' => 30, 'start_date' => now()->toDateString()],
            ],
        ])->assertOk();

        $orgs = $employee->fresh()->organizations;

        $this->assertCount(1, $orgs);
        $this->assertSame('TEEDU Academy', $orgs->first()->name);
    }

    // ── Reading back what was written ─────────────────────────────────────

    /**
     * The wizard has to be able to reload what it saved.
     *
     * The resource was still reading a secondment off the free-text table that
     * was dropped, so every field came back null and reopening somebody showed
     * an empty panel however it had been filled in — and saving from there
     * wiped the real rows.
     */
    public function test_a_secondment_reads_back_with_its_terms_intact(): void
    {
        $this->second(['allocation_pct' => 40, 'engagement_type' => 'advisory'])->assertCreated();

        $this->getJson("/api/v1/employees/{$this->employee->id}?org={$this->home->slug}")
            ->assertOk()
            ->assertJsonPath('data.secondments.0.organization_id', $this->host->id)
            ->assertJsonPath('data.secondments.0.organization_name', 'Tiberbu Healthnet')
            ->assertJsonPath('data.secondments.0.engagement_type', 'advisory')
            ->assertJsonPath('data.secondments.0.allocation_pct', 40)
            ->assertJsonPath('data.secondments.0.is_billable', true)
            ->assertJsonPath('data.secondments.0.role', 'Product Manager');
    }

    /**
     * Benefits and statutory registration were written by the wizard and never
     * returned, so opening somebody to change one field re-sent every flag as
     * false and blanked their plan and provider.
     */
    public function test_benefits_and_compliance_read_back(): void
    {
        /*
         * These live behind the resource's sensitive block, which still gates
         * on the HR role name rather than the permission catalogue — the same
         * disagreement the policies had, one layer further out. Granted here so
         * the test measures the round-trip and not that gate.
         */
        $hr = User::factory()->create();
        $role = Role::create([
            'name' => Role::ORG_HR, 'display_name' => 'HR',
            'scope' => 'organization', 'permissions' => [
                Permission::EMPLOYEES_VIEW, Permission::EMPLOYEES_UPDATE,
            ],
        ]);
        $hr->roles()->attach($role->id, ['organization_id' => $this->home->id]);
        $hr->forgetCachedRoles();
        Sanctum::actingAs($hr->fresh());

        $this->putJson("/api/v1/employees/{$this->employee->id}?org={$this->home->slug}", [
            'medical_plan' => 'Jubilee Health', 'pension_provider' => 'Sanlam Kenya',
            'benefit_medical' => true, 'benefit_pension' => true,
            'kra_registered' => true, 'has_work_permit' => false,
        ])->assertOk();

        $this->getJson("/api/v1/employees/{$this->employee->id}?org={$this->home->slug}")
            ->assertOk()
            ->assertJsonPath('data.medical_plan', 'Jubilee Health')
            ->assertJsonPath('data.pension_provider', 'Sanlam Kenya')
            ->assertJsonPath('data.benefit_medical', true)
            ->assertJsonPath('data.benefit_pension', true)
            ->assertJsonPath('data.kra_registered', true)
            ->assertJsonPath('data.has_work_permit', false);
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
