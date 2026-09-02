<?php

namespace Tests\Feature\Project;

use App\Models\Account\Account;
use App\Models\Account\AccountContact;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The project charter — the governing facts on the Details tab: scope, charter,
 * PIP, product, the execution window and completion promises, and the client's
 * own people. Client contacts must belong to the project's client, and the
 * governance roles live in the team.
 */
class ProjectCharterTest extends TestCase
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
        DefaultReferenceData::seedFor($this->org->id);

        $this->account = Account::create(['organization_id' => $this->org->id, 'name' => 'UNOC']);
        $this->project = Project::create([
            'organization_id' => $this->org->id, 'account_id' => $this->account->id, 'name' => 'Versa SD-WAN',
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
        Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id, 'first_name' => 'T', 'last_name' => 'P',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01', 'user_id' => $user->id,
        ]);
        Sanctum::actingAs($user);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}?org={$this->org->slug}";
    }

    private function contact(?int $accountId = null): AccountContact
    {
        return AccountContact::create([
            'organization_id' => $this->org->id, 'account_id' => $accountId ?? $this->account->id,
            'first_name' => 'Jane', 'last_name' => 'Client', 'designation' => 'CIO',
        ]);
    }

    public function test_the_charter_fields_are_saved(): void
    {
        $this->editor();

        $res = $this->putJson($this->url(), [
            'name' => 'Versa SD-WAN',
            'scope' => 'All 12 regional branches.', 'charter' => 'Board-approved.', 'pip' => 'Three phases.',
            'product' => 'Versa SD-WAN', 'project_email' => 'sdwan@unoc.example',
            'execution_start' => '2026-03-01', 'execution_end' => '2026-12-01',
            'charter_completion_date' => '2026-11-15', 'contract_completion_date' => '2026-12-31',
        ])->assertOk();

        $this->assertSame('All 12 regional branches.', $res->json('data.scope'));
        $this->assertSame('Versa SD-WAN', $res->json('data.product'));
        $this->assertSame('2026-12-01', $res->json('data.execution_end'));
        $this->assertSame('2026-12-31', $res->json('data.contract_completion_date'));
    }

    public function test_a_client_contact_must_belong_to_the_projects_client(): void
    {
        $this->editor();
        $ours = $this->contact();

        $this->putJson($this->url(), ['name' => 'Versa SD-WAN', 'client_contact_id' => $ours->id])
            ->assertOk()->assertJsonPath('data.client_contact.id', $ours->id);

        // A contact of a different account cannot be named.
        $other = Account::create(['organization_id' => $this->org->id, 'name' => 'Someone else']);
        $theirs = $this->contact($other->id);

        $this->putJson($this->url(), ['name' => 'Versa SD-WAN', 'account_id' => $this->account->id, 'client_sponsor_contact_id' => $theirs->id])
            ->assertStatus(422)->assertJsonValidationErrors('client_sponsor_contact_id');
    }

    public function test_the_account_contacts_are_offered_for_the_pickers(): void
    {
        $this->editor();
        $this->contact();

        $res = $this->getJson($this->url())->assertOk();
        $this->assertCount(1, $res->json('data.account.contacts'));
        $this->assertSame('Jane Client', $res->json('data.account.contacts.0.name'));
    }

    public function test_governance_roles_are_assigned_through_the_team(): void
    {
        $this->editor();
        $emp = Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id, 'first_name' => 'Sam', 'last_name' => 'Sponsor',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);

        $res = $this->postJson($this->url('/team/people'), [
            'employee_id' => $emp->id, 'role_kind' => 'sponsor',
        ])->assertCreated();

        $this->assertCount(1, $res->json('data.governance.sponsor'));
        $this->assertSame('Sam Sponsor', $res->json('data.governance.sponsor.0.name'));
    }
}
