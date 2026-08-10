<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\PayrollPeriod;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One organisation's payroll cannot be reached from another.
 *
 * The payroll policy takes no model — `view(User $user)` rather than
 * `view(User $user, PayrollPeriod $period)` — so it can only answer "may this
 * person see payroll", never "may they see *this* payroll". Everything
 * therefore rests on the query layer, and that is worth proving rather than
 * assuming: payroll is the one place where reaching across a tenant boundary
 * would expose every salary in another company.
 *
 * It does hold. `PayrollPeriod` carries the organisation global scope, and the
 * two places the service deliberately lifts it both re-apply an explicit
 * organisation filter on the next line. These tests exist so that stays true.
 */
class PayrollScopingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $mine;
    private Organization $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine   = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->theirs = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        app()->instance('current.organization_id', $this->mine->id);
    }

    private function signIn(array $permissions): User
    {
        $user = User::factory()->create();

        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Payroll', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->mine->id]);
        $user->forgetCachedRoles();

        Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->mine->id,
            'first_name' => 'Payroll', 'last_name' => 'Officer',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        return $user->fresh();
    }

    private function period(Organization $org): PayrollPeriod
    {
        return PayrollPeriod::withoutGlobalScopes()->create([
            'organization_id' => $org->id,
            'name'       => 'December 2026',
            'start_date' => '2026-12-01',
            'end_date'   => '2026-12-31',
            'status'     => PayrollPeriod::STATUS_DRAFT,
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/payroll{$path}?org={$this->mine->slug}";
    }

    // ── Reading ───────────────────────────────────────────────────────────

    public function test_the_list_shows_only_this_organisations_periods(): void
    {
        $this->signIn([Permission::PAYROLL_VIEW]);

        $this->period($this->mine);
        $this->period($this->theirs);

        $body = $this->getJson($this->url())->assertOk()->json();
        $rows = $body['data'] ?? $body;

        $this->assertCount(1, $rows);
    }

    /**
     * The one that matters. The policy cannot tell these apart, so the query
     * layer has to — and a hit here would expose every salary in the other
     * company.
     */
    public function test_another_organisations_period_is_not_reachable_by_id(): void
    {
        $this->signIn([Permission::PAYROLL_VIEW]);

        $theirs = $this->period($this->theirs);

        $this->getJson($this->url('/' . $theirs->id))->assertNotFound();
    }

    public function test_another_organisations_period_cannot_be_processed(): void
    {
        $this->signIn([Permission::PAYROLL_PROCESS, Permission::PAYROLL_VIEW]);

        $theirs = $this->period($this->theirs);

        $this->postJson($this->url("/{$theirs->id}/process"))->assertNotFound();
        $this->assertSame(PayrollPeriod::STATUS_DRAFT, $theirs->fresh()->status);
    }

    public function test_another_organisations_period_cannot_be_approved(): void
    {
        $this->signIn([Permission::PAYROLL_APPROVE, Permission::PAYROLL_VIEW]);

        $theirs = $this->period($this->theirs);

        $this->postJson($this->url("/{$theirs->id}/approve"))->assertNotFound();
    }

    /** Our own is reachable, so the tests above are proving scope not breakage. */
    public function test_our_own_period_is_reachable(): void
    {
        $this->signIn([Permission::PAYROLL_VIEW]);

        $mine = $this->period($this->mine);

        $this->getJson($this->url('/' . $mine->id))->assertOk();
    }

    // ── The permission still has to be held ───────────────────────────────

    public function test_payroll_is_not_readable_without_the_permission(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW]);

        $this->period($this->mine);

        $this->getJson($this->url())->assertForbidden();
    }

    /** Reading payroll is not running it. */
    public function test_reading_payroll_does_not_confer_processing_it(): void
    {
        $this->signIn([Permission::PAYROLL_VIEW]);

        $mine = $this->period($this->mine);

        $this->postJson($this->url("/{$mine->id}/process"))->assertForbidden();
        $this->postJson($this->url("/{$mine->id}/approve"))->assertForbidden();
    }
}
