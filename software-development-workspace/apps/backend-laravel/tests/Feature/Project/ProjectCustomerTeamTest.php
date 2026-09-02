<?php

namespace Tests\Feature\Project;

use App\Models\Account\Account;
use App\Models\Account\AccountContact;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Project\ProjectCustomerRole;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The customer team & stakeholder register — the client's side of governance,
 * built on the account's contacts.
 */
class ProjectCustomerTeamTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Account $account;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        $this->account = Account::create(['organization_id' => $this->org->id, 'name' => 'UNOC']);
        $this->project = Project::create([
            'organization_id' => $this->org->id, 'account_id' => $this->account->id, 'name' => 'SD-WAN',
        ]);
    }

    private function editor(): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization',
            'permissions' => [Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}/customer-team{$path}?org={$this->org->slug}";
    }

    public function test_the_section_lists_the_client_and_the_role_catalogue(): void
    {
        $this->editor();

        $res = $this->getJson($this->url())->assertOk();

        $this->assertSame('UNOC', $res->json('organization.name'));
        $this->assertCount(count(ProjectCustomerRole::CATALOG), $res->json('roles'));
    }

    public function test_a_contact_can_be_added_and_wired_to_a_customer_role(): void
    {
        $this->editor();

        $this->postJson($this->url('/contacts'), [
            'first_name' => 'Ann', 'last_name' => 'Client', 'email' => 'ann@unoc.example', 'category' => 'primary',
        ])->assertCreated();

        $contact = AccountContact::where('account_id', $this->account->id)->firstOrFail();

        $res = $this->postJson($this->url('/roles'), [
            'role_key' => 'customer_sponsor', 'account_contact_id' => $contact->id, 'responsibility' => 'Signs off',
        ])->assertOk();

        $sponsor = collect($res->json('roles'))->firstWhere('role_key', 'customer_sponsor');
        $this->assertSame($contact->id, $sponsor['contact']['id']);
        $this->assertSame('Signs off', $sponsor['responsibility']);
    }

    public function test_a_role_cannot_point_at_another_accounts_contact(): void
    {
        $this->editor();
        $other = Account::create(['organization_id' => $this->org->id, 'name' => 'Other']);
        $foreign = AccountContact::create([
            'organization_id' => $this->org->id, 'account_id' => $other->id, 'first_name' => 'X',
        ]);

        $this->postJson($this->url('/roles'), ['role_key' => 'customer_sponsor', 'account_contact_id' => $foreign->id])
            ->assertStatus(422);
    }

    public function test_stakeholders_can_be_registered_and_removed(): void
    {
        $this->editor();

        $res = $this->postJson($this->url('/stakeholders'), [
            'name' => 'Board Chair', 'persona' => 'Decision maker', 'influence' => 'high', 'interest' => 'medium',
        ])->assertCreated();

        $this->assertCount(1, $res->json('stakeholders'));
        $id = $res->json('stakeholders.0.id');

        $this->deleteJson($this->url("/stakeholders/{$id}"))->assertOk()
            ->assertJsonCount(0, 'stakeholders');
    }
}
