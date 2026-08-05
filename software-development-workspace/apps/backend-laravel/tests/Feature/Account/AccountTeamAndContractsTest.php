<?php

namespace Tests\Feature\Account;

use App\Models\Account\Account;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The account team and its contracts, both ported from Plmv2.
 *
 * What is worth fixing here is not that the rows save. It is that a team
 * cannot be filled with people from another tenant, that a contract cannot end
 * before it starts, and that the reference lists the edit form depends on are
 * readable by everyone who may edit a customer — the last one being the exact
 * failure that left the travel wizard with silently empty dropdowns.
 */
class AccountTeamAndContractsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
    }

    private function actor(array $permissions): User
    {
        $user = User::factory()->create();

        $role = Role::create([
            'name'         => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        Sanctum::actingAs($user);

        return $user;
    }

    private function employee(?Organization $org = null): Employee
    {
        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => ($org ?? $this->org)->id,
            'first_name' => 'Test', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function account(): Account
    {
        return Account::create([
            'organization_id' => $this->org->id,
            'name'            => 'Customer ' . fake()->unique()->numberBetween(1, 999999),
        ]);
    }

    private function url(Account $a, string $path = ''): string
    {
        return "/api/v1/accounts/{$a->id}{$path}?org={$this->org->slug}";
    }

    // ── Team ──────────────────────────────────────────────────────────────

    public function test_somebody_can_be_added_to_the_team(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->account();
        $person  = $this->employee();

        $res = $this->postJson($this->url($account, '/team'), [
            'employee_id' => $person->id, 'role' => 'Lead engineer',
        ])->assertOk();

        $this->assertSame('Lead engineer', $res->json('data.team.0.role'));
    }

    public function test_adding_the_same_person_twice_does_not_duplicate_them(): void
    {
        // Two rows for one person makes "who works this account" a list with
        // the same name in it, and the unique index would otherwise 500.
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->account();
        $person  = $this->employee();

        $this->postJson($this->url($account, '/team'), ['employee_id' => $person->id, 'role' => 'Support'])->assertOk();
        $res = $this->postJson($this->url($account, '/team'), ['employee_id' => $person->id, 'role' => 'Lead'])->assertOk();

        $this->assertCount(1, $res->json('data.team'));
        $this->assertSame('Lead', $res->json('data.team.0.role'));
    }

    public function test_somebody_from_another_organisation_cannot_be_put_on_the_team(): void
    {
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);

        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);

        $this->postJson($this->url($this->account(), '/team'), ['employee_id' => $this->employee($beta)->id])
            ->assertStatus(422)->assertJsonValidationErrors('employee_id');
    }

    public function test_the_team_cannot_be_changed_without_the_right_to_edit_the_customer(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW]);

        $this->postJson($this->url($this->account(), '/team'), ['employee_id' => $this->employee()->id])
            ->assertForbidden();
    }

    public function test_a_team_member_of_another_customer_is_not_reachable(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $mine    = $this->account();
        $other   = $this->account();

        $memberId = $this->postJson($this->url($other, '/team'), ['employee_id' => $this->employee()->id])
            ->json('data.team.0.id');

        $this->deleteJson($this->url($mine, "/team/{$memberId}"))->assertNotFound();
    }

    // ── Contracts ─────────────────────────────────────────────────────────

    public function test_a_contract_can_be_recorded_against_a_customer(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->account();

        $this->postJson($this->url($account, '/contracts'), [
            'name' => 'Annual support', 'contract_value' => 1200000,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ])->assertCreated();

        $this->assertSame(1, $account->contracts()->count());
    }

    public function test_a_contract_cannot_end_before_it_starts(): void
    {
        // A typo every time, and it silently breaks "what expires soon".
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);

        $this->postJson($this->url($this->account(), '/contracts'), [
            'name' => 'Backwards', 'start_date' => '2026-12-31', 'end_date' => '2026-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors('end_date');
    }

    public function test_a_contract_belongs_to_the_customers_organisation_not_the_request(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->account();

        $this->postJson($this->url($account, '/contracts'), [
            'name' => 'Support', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ])->assertCreated();

        $this->assertSame($account->organization_id, $account->contracts()->first()->organization_id);
    }

    public function test_expiry_is_reported_in_days_rather_than_left_to_be_worked_out(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->account();

        $res = $this->postJson($this->url($account, '/contracts'), [
            'name' => 'Expiring', 'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
        ])->assertCreated();

        $this->assertSame(30, $res->json('data.days_to_expiry'));
        $this->assertFalse($res->json('data.is_expired'));
    }

    public function test_a_contract_that_has_run_out_says_so(): void
    {
        // Derived rather than stored: a stored flag is only correct until the
        // next midnight, and nothing runs at midnight to fix it.
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->account();

        $res = $this->postJson($this->url($account, '/contracts'), [
            'name' => 'Lapsed', 'start_date' => now()->subYears(2)->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
        ])->assertCreated();

        $this->assertTrue($res->json('data.is_expired'));
    }

    public function test_a_contract_of_another_customer_is_not_reachable(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $mine  = $this->account();
        $other = $this->account();

        $id = $this->postJson($this->url($other, '/contracts'), [
            'name' => 'Theirs', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ])->json('data.id');

        $this->putJson($this->url($mine, "/contracts/{$id}"), [
            'name' => 'Hijacked', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ])->assertNotFound();
    }

    public function test_contracts_and_team_come_back_with_the_customer(): void
    {
        // Loaded by name in one place, so a relation added to the resource
        // cannot be missed by one endpoint and show an empty tab.
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->account();

        $this->postJson($this->url($account, '/team'), ['employee_id' => $this->employee()->id])->assertOk();
        $this->postJson($this->url($account, '/contracts'), [
            'name' => 'Support', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ])->assertCreated();

        $res = $this->getJson($this->url($account))->assertOk();

        $this->assertCount(1, $res->json('data.team'));
        $this->assertCount(1, $res->json('data.contracts'));
    }

    // ── Lookups ───────────────────────────────────────────────────────────

    public function test_the_edit_forms_reference_lists_are_readable_by_anyone_who_can_see_customers(): void
    {
        // Gating these on the reference-data permission would leave everyone
        // else with empty dropdowns and no error to explain them.
        $this->actor([Permission::ACCOUNTS_VIEW]);

        $this->getJson("/api/v1/accounts/lookups?org={$this->org->slug}")
            ->assertOk()->assertJsonStructure(['types', 'sectors', 'subsectors']);
    }

    public function test_a_provisioned_organisation_starts_with_its_reference_lists(): void
    {
        // They shipped empty, which meant every customer had a blank sector —
        // a blank column being exactly what a lookup table exists to prevent,
        // and there is no screen for adding one.
        DefaultReferenceData::seedFor($this->org->id);
        $this->actor([Permission::ACCOUNTS_VIEW]);

        $res = $this->getJson("/api/v1/accounts/lookups?org={$this->org->slug}")->assertOk();

        $this->assertNotEmpty($res->json('types'));
        $this->assertContains('BFSI', array_column($res->json('sectors'), 'name'));
    }

    public function test_seeding_the_reference_lists_twice_does_not_duplicate_them(): void
    {
        // It runs from two places — the back-fill migration and provisioning —
        // and an organisation could plausibly meet both.
        DefaultReferenceData::seedFor($this->org->id);
        DefaultReferenceData::seedFor($this->org->id);

        $this->actor([Permission::ACCOUNTS_VIEW]);
        $res = $this->getJson("/api/v1/accounts/lookups?org={$this->org->slug}")->assertOk();

        $this->assertCount(count(DefaultReferenceData::SECTORS), $res->json('sectors'));
    }
}
