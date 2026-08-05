<?php

namespace Tests\Feature\Account;

use App\Models\Account\Account;
use App\Models\Account\AccountContact;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The customer master's HTTP surface.
 *
 * Two properties are load-bearing and neither is visible from reading a
 * response: that one organisation cannot reach another's customers, and that
 * "primary contact" means exactly one person. Both fail silently — the first
 * looks like a working list, the second like a working form.
 */
class AccountApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
    }

    private function actor(array $permissions, ?Organization $org = null): array
    {
        $org  = $org ?? $this->org;
        $user = User::factory()->create();

        $role = Role::create([
            'name'         => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test',
            'scope'        => 'organization',
            'permissions'  => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $org->id]);
        $user->forgetCachedRoles();

        $employee = Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $org->id,
            'first_name'              => 'Test', 'last_name' => 'User',
            'employment_type'         => 'permanent', 'date_hired' => '2024-01-01',
            'user_id'                 => $user->id,
        ]);

        Sanctum::actingAs($user);

        return [$user, $employee];
    }

    private function account(array $attrs = [], ?Organization $org = null): Account
    {
        return Account::create($attrs + [
            'organization_id' => ($org ?? $this->org)->id,
            'name'            => 'Customer ' . fake()->unique()->numberBetween(1, 999999),
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/accounts{$path}?org={$this->org->slug}";
    }

    // ── Authorization ─────────────────────────────────────────────────────

    public function test_listing_requires_a_permission(): void
    {
        $this->actor(['leave.view']);
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_view_own_shows_only_the_managers_own_customers(): void
    {
        [, $employee] = $this->actor([Permission::ACCOUNTS_VIEW_OWN]);

        $mine = $this->account(['account_manager_id' => $employee->id]);
        $this->account();   // somebody else's

        $res = $this->getJson($this->url())->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame($mine->id, $res->json('data.0.id'));
    }

    public function test_view_own_cannot_be_widened_by_a_query_parameter(): void
    {
        // The scope is resolved from the authenticated user, not the request.
        // Reading it from input would let a caller ask for someone else's book.
        [, $employee] = $this->actor([Permission::ACCOUNTS_VIEW_OWN]);
        $this->account(['account_manager_id' => $employee->id]);
        $other = $this->account();

        $res = $this->getJson($this->url() . "&account_manager_id={$other->account_manager_id}")->assertOk();
        $this->assertCount(1, $res->json('data'));
    }

    public function test_a_customer_from_another_organisation_is_not_reachable(): void
    {
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $foreign = $this->account([], $beta);

        $this->actor([Permission::ACCOUNTS_VIEW]);

        $this->getJson("/api/v1/accounts/{$foreign->id}?org={$this->org->slug}")->assertNotFound();
    }

    public function test_creating_requires_the_create_permission(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW]);
        $this->postJson($this->url(), ['name' => 'Nope'])->assertForbidden();
    }

    // ── Reassignment is a separate authority ──────────────────────────────

    public function test_update_cannot_reassign_the_account_manager_without_the_assign_permission(): void
    {
        [, $employee] = $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->account();

        $this->putJson($this->url("/{$account->id}"), [
            'name'               => $account->name,
            'account_manager_id' => $employee->id,
        ])->assertOk();

        // The edit succeeds; the reassignment inside it does not.
        $this->assertNull($account->fresh()->account_manager_id);
    }

    public function test_update_reassigns_when_the_assign_permission_is_held(): void
    {
        [, $employee] = $this->actor([
            Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE, Permission::ACCOUNTS_ASSIGN,
        ]);
        $account = $this->account();

        $this->putJson($this->url("/{$account->id}"), [
            'name'               => $account->name,
            'account_manager_id' => $employee->id,
        ])->assertOk();

        $this->assertSame($employee->id, $account->fresh()->account_manager_id);
    }

    // ── Validation ────────────────────────────────────────────────────────

    public function test_a_duplicate_customer_name_is_rejected_within_an_organisation(): void
    {
        $this->actor([Permission::ACCOUNTS_CREATE, Permission::ACCOUNTS_VIEW]);
        $existing = $this->account();

        $this->postJson($this->url(), ['name' => $existing->name])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_the_same_customer_name_is_allowed_in_a_different_organisation(): void
    {
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $this->account(['name' => 'Shared Name'], $beta);

        $this->actor([Permission::ACCOUNTS_CREATE, Permission::ACCOUNTS_VIEW]);

        $this->postJson($this->url(), ['name' => 'Shared Name'])->assertCreated();
    }

    public function test_a_sector_belonging_to_another_organisation_is_rejected(): void
    {
        // `exists` alone would accept it, which is a cross-tenant reference
        // that looks like ordinary reference data on the screen.
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $foreignSector = \App\Models\Account\AccountSector::create([
            'organization_id' => $beta->id, 'name' => 'Banking',
        ]);

        $this->actor([Permission::ACCOUNTS_CREATE]);

        $this->postJson($this->url(), ['name' => 'X', 'account_sector_id' => $foreignSector->id])
            ->assertStatus(422)->assertJsonValidationErrors('account_sector_id');
    }

    // ── Contacts: "primary" must mean exactly one ─────────────────────────

    public function test_the_first_contact_becomes_primary_without_being_asked(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->account();

        $this->postJson($this->url("/{$account->id}/contacts"), ['first_name' => 'Ada'])
            ->assertCreated()->assertJsonPath('data.is_primary', true);
    }

    public function test_promoting_a_contact_demotes_the_previous_primary(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->account();

        $first = $this->postJson($this->url("/{$account->id}/contacts"), ['first_name' => 'Ada'])->json('data.id');
        $this->postJson($this->url("/{$account->id}/contacts"), ['first_name' => 'Grace', 'is_primary' => true])
            ->assertCreated();

        $this->assertSame(
            1,
            AccountContact::where('account_id', $account->id)->where('is_primary', true)->count(),
            'Exactly one contact may be primary.'
        );
        $this->assertFalse(AccountContact::find($first)->is_primary);
    }

    public function test_deleting_the_primary_contact_promotes_a_replacement(): void
    {
        // Contacts but no primary is the state that makes "who do we call"
        // unanswerable, and nothing on screen shows it.
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->account();

        $primary = $this->postJson($this->url("/{$account->id}/contacts"), ['first_name' => 'Ada'])->json('data.id');
        $this->postJson($this->url("/{$account->id}/contacts"), ['first_name' => 'Grace'])->assertCreated();

        $this->deleteJson($this->url("/{$account->id}/contacts/{$primary}"))->assertOk();

        $this->assertSame(
            1,
            AccountContact::where('account_id', $account->id)->where('is_primary', true)->count(),
            'A replacement primary must be promoted.'
        );
    }

    public function test_a_contact_cannot_be_edited_through_a_different_customer(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $a = $this->account();
        $b = $this->account();

        $contact = $this->postJson($this->url("/{$a->id}/contacts"), ['first_name' => 'Ada'])->json('data.id');

        $this->putJson($this->url("/{$b->id}/contacts/{$contact}"), ['first_name' => 'Hacked'])
            ->assertNotFound();
    }
}
