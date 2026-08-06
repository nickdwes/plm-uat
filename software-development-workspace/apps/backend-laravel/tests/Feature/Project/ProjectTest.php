<?php

namespace Tests\Feature\Project;

use App\Models\Account\Account;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Pipeline\Opportunity;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Projects — the work a won deal starts.
 *
 * Deliberately short of a full project module. What is proved here is the part
 * that would otherwise go quietly wrong: that "late" means two different things
 * and the screens must not conflate them, that a project going back into flight
 * does not keep a delivery date, and that the deal's value is not readable
 * through a project screen by somebody without the commercial elevation.
 */
class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        DefaultReferenceData::seedFor($this->org->id);

        $this->account = Account::create([
            'organization_id' => $this->org->id, 'name' => 'Kenya Commercial Bank',
        ]);
    }

    private function actor(array $permissions): Employee
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        $employee = $this->employee();
        $employee->update(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function employee(): Employee
    {
        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Test', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/projects{$path}?org={$this->org->slug}";
    }

    private function deal(): Opportunity
    {
        return Opportunity::create([
            'organization_id' => $this->org->id, 'account_id' => $this->account->id,
            'name' => 'Integrate AI lead scoring module', 'amount' => 1500000,
        ]);
    }

    private function project(array $attrs = []): Project
    {
        return Project::create($attrs + [
            'organization_id' => $this->org->id,
            'name'            => 'Employee Portal Revamp',
        ]);
    }

    // ── Creating ──────────────────────────────────────────────────────────

    public function test_a_project_is_given_a_reference(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_CREATE]);

        $res = $this->postJson($this->url(), ['name' => 'Employee Portal Revamp'])->assertCreated();

        $this->assertMatchesRegularExpression('/^PR-\d+$/', $res->json('data.reference'));
        $this->assertSame('kickoff', $res->json('data.status'));
    }

    public function test_a_project_cannot_be_delivered_before_it_started(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_CREATE]);

        $this->postJson($this->url(), [
            'name' => 'Backwards', 'started_on' => '2026-06-01', 'delivered_on' => '2026-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors('delivered_on');
    }

    // ── Late means two things ─────────────────────────────────────────────

    public function test_a_project_still_running_past_its_deadline_is_overdue(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_CREATE]);
        $p = $this->project(['deadline' => now()->subWeek()->toDateString(), 'status' => 'in_flight']);

        $res = $this->getJson($this->url("/{$p->id}"))->assertOk();

        $this->assertTrue($res->json('data.is_overdue'));
        // Not yet delivered, so whether it *was* late is not a question that
        // has an answer. A project in flight is not a failure.
        $this->assertNull($res->json('data.was_late'));
    }

    public function test_a_delivered_project_reports_whether_it_was_late(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_CREATE]);
        $p = $this->project([
            'deadline' => '2026-01-31', 'delivered_on' => '2026-02-15', 'status' => 'delivered',
        ]);

        $res = $this->getJson($this->url("/{$p->id}"))->assertOk();

        $this->assertTrue($res->json('data.was_late'));
        // Delivered, so it is no longer overdue — those are different states.
        $this->assertFalse($res->json('data.is_overdue'));
    }

    public function test_a_cancelled_project_is_not_reported_as_overdue(): void
    {
        // It is not late; it is cancelled, and counting it as late would make
        // a delivery report worse than useless.
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_CREATE]);
        $p = $this->project(['deadline' => now()->subMonth()->toDateString(), 'status' => 'cancelled']);

        $this->getJson($this->url("/{$p->id}"))->assertOk()->assertJsonPath('data.is_overdue', false);
    }

    public function test_marking_a_project_delivered_stamps_the_date(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_CREATE, Permission::PROJECTS_UPDATE]);
        $p = $this->project(['deadline' => '2026-06-30']);

        $res = $this->putJson($this->url("/{$p->id}"), [
            'name' => 'Employee Portal Revamp', 'status' => 'delivered',
        ])->assertOk();

        $this->assertNotNull($res->json('data.delivered_on'));
    }

    public function test_a_project_going_back_into_flight_loses_its_delivery_date(): void
    {
        /*
         * A delivered_on left behind on a project that reopened is how "was it
         * late" starts lying — it would report a date for something not yet
         * finished.
         */
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_CREATE, Permission::PROJECTS_UPDATE]);
        $p = $this->project(['deadline' => '2026-06-30', 'delivered_on' => '2026-06-01', 'status' => 'delivered']);

        $res = $this->putJson($this->url("/{$p->id}"), [
            'name' => 'Employee Portal Revamp', 'status' => 'in_flight',
        ])->assertOk();

        $this->assertNull($res->json('data.delivered_on'));
        $this->assertNull($res->json('data.was_late'));
    }

    public function test_overdue_can_be_filtered_for_directly(): void
    {
        // The question people arrive with, and one a status filter cannot answer.
        $this->actor([Permission::PROJECTS_VIEW]);

        $this->project(['deadline' => now()->subWeek()->toDateString(), 'status' => 'in_flight']);
        $this->project(['deadline' => now()->addMonth()->toDateString(), 'status' => 'in_flight']);
        $this->project([
            'deadline' => now()->subYear()->toDateString(), 'status' => 'delivered',
            'delivered_on' => now()->subYear()->toDateString(),
        ]);

        $this->getJson($this->url() . '&state=overdue')->assertOk()->assertJsonCount(1, 'data');
    }

    // ── Commercials ───────────────────────────────────────────────────────

    public function test_the_contract_value_is_not_readable_through_a_project(): void
    {
        // A project screen is not a way round the commercial elevation.
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_CREATE, Permission::PIPELINE_VIEW]);
        $p = $this->project(['opportunity_id' => $this->deal()->id, 'contract_value' => 1200000]);

        $res = $this->getJson($this->url("/{$p->id}"))->assertOk();

        $this->assertNull($res->json('data.contract_value'));
        $this->assertFalse($res->json('data.commercials_visible'));
    }

    public function test_the_elevation_reveals_it(): void
    {
        $this->actor([
            Permission::PROJECTS_VIEW, Permission::PROJECTS_CREATE,
            Permission::PIPELINE_VIEW, Permission::PIPELINE_VIEW_COMMERCIALS,
        ]);
        $p = $this->project(['opportunity_id' => $this->deal()->id, 'contract_value' => 1200000]);

        $this->getJson($this->url("/{$p->id}"))->assertOk()
            ->assertJsonPath('data.commercials_visible', true);
    }

    // ── Staffing and visibility ───────────────────────────────────────────

    public function test_staffing_a_project_is_a_separate_authority_from_editing_it(): void
    {
        // Putting somebody on a project commits their time; correcting a
        // deadline does not.
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE]);
        $p = $this->project();

        $this->postJson($this->url("/{$p->id}/team"), ['employee_id' => $this->employee()->id])
            ->assertForbidden();
    }

    public function test_somebody_can_be_put_on_a_project(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_ASSIGN]);
        $p = $this->project();

        $res = $this->postJson($this->url("/{$p->id}/team"), [
            'employee_id' => $this->employee()->id, 'role' => 'Network engineer',
        ])->assertOk();

        $this->assertSame('Network engineer', $res->json('data.team.0.role'));
    }

    public function test_somebody_delivering_a_project_can_open_it(): void
    {
        $me = $this->actor([Permission::PROJECTS_VIEW_OWN]);
        $p  = $this->project(['project_lead_id' => $this->employee()->id]);
        $p->team()->create(['organization_id' => $this->org->id, 'employee_id' => $me->id]);

        $this->getJson($this->url("/{$p->id}"))->assertOk();
    }

    public function test_a_project_that_is_neither_yours_nor_your_team_is_not_visible(): void
    {
        $this->actor([Permission::PROJECTS_VIEW_OWN]);
        $p = $this->project(['project_lead_id' => $this->employee()->id]);

        $this->getJson($this->url("/{$p->id}"))->assertForbidden();
    }

    public function test_projects_use_their_own_permissions_not_the_pipelines(): void
    {
        // A deal and its delivery are worked by different people; the whole
        // point of a kickoff is the handover.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);

        $this->getJson($this->url())->assertForbidden();
    }
}
