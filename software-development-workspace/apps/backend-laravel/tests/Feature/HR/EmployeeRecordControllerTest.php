<?php

namespace Tests\Feature\HR;

use App\Models\Account\Account;
use App\Models\Asset\Asset;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\Support\Ticket;
use App\Models\Support\TicketPriority;
use App\Models\Support\TicketStatus;
use App\Models\Task\Task;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One employee's slice of tasks, projects, support tickets and assets.
 *
 * Each of these modules keeps its own records; what is tested here is the
 * predicate that picks out "this person's" from the whole table, not the
 * modules themselves.
 */
class EmployeeRecordControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Konvergenz', 'slug' => 'konvergenz']);
        app()->instance('current.organization_id', $this->org->id);

        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'HR', 'scope' => 'organization',
            'permissions' => [Permission::EMPLOYEES_VIEW],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        $this->employee = Employee::create([
            'employee_number' => 'E1', 'primary_organization_id' => $this->org->id,
            'first_name' => 'James', 'last_name' => 'Kinyanjui', 'employment_type' => 'permanent',
            'date_hired' => '2024-01-01', 'status' => 'active',
        ]);
    }

    private function url(string $path): string
    {
        return "/api/v1/employees/{$this->employee->id}{$path}?org={$this->org->slug}";
    }

    // ── Tasks ─────────────────────────────────────────────────────────────

    public function test_it_lists_tasks_assigned_to_this_person(): void
    {
        Task::create([
            'organization_id' => $this->org->id, 'title' => 'Migrate the database',
            'status' => 'in_progress', 'assigned_to' => $this->employee->id,
        ]);
        Task::create([
            'organization_id' => $this->org->id, 'title' => 'Somebody else\'s task',
            'status' => 'todo',
        ]);

        $rows = $this->getJson($this->url('/tasks'))->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Migrate the database', $rows[0]['title']);
    }

    public function test_in_progress_tasks_come_before_completed_ones(): void
    {
        Task::create([
            'organization_id' => $this->org->id, 'title' => 'Done already',
            'status' => 'completed', 'assigned_to' => $this->employee->id,
        ]);
        Task::create([
            'organization_id' => $this->org->id, 'title' => 'Still going',
            'status' => 'in_progress', 'assigned_to' => $this->employee->id,
        ]);

        $rows = $this->getJson($this->url('/tasks'))->assertOk()->json('data');

        $this->assertSame('Still going', $rows[0]['title']);
    }

    // ── Projects ──────────────────────────────────────────────────────────

    public function test_it_lists_projects_this_person_leads(): void
    {
        Project::create([
            'organization_id' => $this->org->id, 'name' => 'Employee Portal Revamp',
            'project_lead_id' => $this->employee->id,
        ]);
        Project::create(['organization_id' => $this->org->id, 'name' => 'Somebody else\'s project']);

        $rows = $this->getJson($this->url('/projects'))->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('lead', $rows[0]['role']);
    }

    public function test_it_lists_projects_this_person_delivers_on_as_team(): void
    {
        $project = Project::create(['organization_id' => $this->org->id, 'name' => 'Delivered by team']);
        $project->team()->create(['organization_id' => $this->org->id, 'employee_id' => $this->employee->id]);

        $rows = $this->getJson($this->url('/projects'))->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('team', $rows[0]['role']);
    }

    // ── Support tickets ───────────────────────────────────────────────────

    private function ticketStatus(): TicketStatus
    {
        return TicketStatus::create([
            'organization_id' => $this->org->id, 'name' => 'Open', 'slug' => 'open', 'state' => 'open',
        ]);
    }

    public function test_a_person_with_no_login_cannot_be_matched_to_any_ticket(): void
    {
        $res = $this->getJson($this->url('/support-tickets'))->assertOk()->json();

        $this->assertSame([], $res['data']);
        $this->assertStringContainsString('no linked login', $res['note']);
    }

    public function test_it_lists_tickets_assigned_to_this_persons_login(): void
    {
        $user = User::factory()->create();
        $this->employee->update(['user_id' => $user->id]);

        Ticket::create([
            'organization_id' => $this->org->id, 'reference' => 'SD-2026-0001',
            'subject' => 'VPN not connecting', 'ticket_status_id' => $this->ticketStatus()->id,
            'assigned_to' => $user->id,
        ]);

        $rows = $this->getJson($this->url('/support-tickets'))->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('assigned', $rows[0]['role']);
    }

    public function test_it_lists_tickets_this_person_raised(): void
    {
        $user = User::factory()->create();
        $this->employee->update(['user_id' => $user->id]);

        Ticket::create([
            'organization_id' => $this->org->id, 'reference' => 'SD-2026-0002',
            'subject' => 'Need a new laptop', 'ticket_status_id' => $this->ticketStatus()->id,
            'requester_user_id' => $user->id,
        ]);

        $rows = $this->getJson($this->url('/support-tickets'))->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('raised', $rows[0]['role']);
    }

    // ── Assets ────────────────────────────────────────────────────────────

    private function asset(array $attrs = []): Asset
    {
        return Asset::create($attrs + [
            'organization_id' => $this->org->id, 'name' => 'Dell Laptop',
            'asset_tag' => 'AT-' . fake()->unique()->numberBetween(1, 9999),
            'category' => 'IT Equipment', 'asset_class' => 'hardware', 'status' => 'assigned',
        ]);
    }

    public function test_it_lists_assets_assigned_to_this_person(): void
    {
        $this->asset(['assigned_to' => $this->employee->id]);
        $this->asset();

        $rows = $this->getJson($this->url('/assets'))->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('assigned', $rows[0]['role']);
    }

    /** Custodian and assignee are different facts about an asset. */
    public function test_a_custodian_is_distinguished_from_an_assignee(): void
    {
        $other = Employee::create([
            'employee_number' => 'E2', 'primary_organization_id' => $this->org->id,
            'first_name' => 'Other', 'last_name' => 'Person', 'employment_type' => 'permanent',
            'date_hired' => '2024-01-01', 'status' => 'active',
        ]);
        $this->asset(['assigned_to' => $other->id, 'custodian_id' => $this->employee->id]);

        $rows = $this->getJson($this->url('/assets'))->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('custodian', $rows[0]['role']);
    }

    // ── Scoping ───────────────────────────────────────────────────────────

    public function test_an_employee_outside_this_organisation_returns_a_404(): void
    {
        $other = Organization::create(['name' => 'Tiberbu', 'slug' => 'tiberbu']);
        $elsewhere = Employee::create([
            'employee_number' => 'E3', 'primary_organization_id' => $other->id,
            'first_name' => 'A', 'last_name' => 'B', 'employment_type' => 'permanent',
            'date_hired' => '2024-01-01', 'status' => 'active',
        ]);

        $this->getJson("/api/v1/employees/{$elsewhere->id}/tasks?org={$this->org->slug}")
            ->assertNotFound();
    }
}
