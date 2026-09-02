<?php

namespace Tests\Feature\Project;

use App\Models\Account\Account;
use App\Models\Account\AccountContact;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Project\ProjectMeeting;
use App\Models\Role;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Project correspondence — the meeting list, and who it offers to invite. The
 * Teams call itself goes through the shared Graph client (tested where that
 * lives); here we prove the project-specific parts: suggestions from the team
 * and the client, the ownership guards, and validation.
 */
class ProjectMeetingTest extends TestCase
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

    private function viewer(): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'V', 'scope' => 'organization', 'permissions' => [Permission::PROJECTS_VIEW],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}/meetings{$path}?org={$this->org->slug}";
    }

    private function employee(string $first, ?string $email): Employee
    {
        return Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id, 'first_name' => $first, 'last_name' => 'X',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01', 'work_email' => $email,
        ]);
    }

    public function test_suggestions_gather_the_team_and_client_deduped_and_grouped(): void
    {
        $this->editor();

        $lead = $this->employee('Lead', 'lead@kns.example');
        $this->project->update(['project_lead_id' => $lead->id]);

        $member = $this->employee('Member', 'member@kns.example');
        $this->project->people()->create([
            'organization_id' => $this->org->id, 'employee_id' => $member->id, 'role_kind' => 'engineer',
        ]);
        // Same person again in another role — must not double up.
        $this->project->people()->create([
            'organization_id' => $this->org->id, 'employee_id' => $lead->id, 'role_kind' => 'sponsor',
        ]);
        $this->employee('NoEmail', null); // no address → not a suggestion

        AccountContact::create([
            'organization_id' => $this->org->id, 'account_id' => $this->account->id,
            'first_name' => 'Jane', 'last_name' => 'Client', 'email' => 'jane@unoc.example',
        ]);

        $res = $this->getJson($this->url())->assertOk();
        $suggestions = collect($res->json('suggestions'));

        $this->assertCount(3, $suggestions); // lead, member, jane — deduped, email-only
        $this->assertSame('Internal', $suggestions->firstWhere('address', 'lead@kns.example')['group']);
        $this->assertSame('Client', $suggestions->firstWhere('address', 'jane@unoc.example')['group']);
    }

    public function test_scheduling_requires_at_least_one_attendee(): void
    {
        $this->editor();

        $this->postJson($this->url(), [
            'subject' => 'Kickoff', 'scheduled_at' => now()->addDay()->toIso8601String(),
            'duration_minutes' => 30, 'attendees' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('attendees');
    }

    public function test_scheduling_needs_the_update_permission(): void
    {
        $this->viewer();

        $this->postJson($this->url(), [
            'subject' => 'Kickoff', 'scheduled_at' => now()->addDay()->toIso8601String(),
            'duration_minutes' => 30, 'attendees' => [['name' => 'A', 'address' => 'a@b.com']],
        ])->assertForbidden();
    }

    public function test_a_meeting_from_another_project_cannot_be_cancelled_here(): void
    {
        $this->editor();

        $other = Project::create(['organization_id' => $this->org->id, 'name' => 'Other']);
        $meeting = ProjectMeeting::create([
            'organization_id' => $this->org->id, 'project_id' => $other->id,
            'subject' => 'X', 'scheduled_at' => now(), 'duration_minutes' => 30, 'attendees' => [],
            'status' => 'scheduled',
        ]);

        $this->deleteJson($this->url("/{$meeting->id}"))->assertNotFound();
    }
}
