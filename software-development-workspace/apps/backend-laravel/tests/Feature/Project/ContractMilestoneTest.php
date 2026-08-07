<?php

namespace Tests\Feature\Project;

use App\Models\Account\Account;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Pipeline\Opportunity;
use App\Models\Project\Project;
use App\Models\Project\ProjectContractMilestone;
use App\Models\Project\ProjectTask;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contract milestones — what the customer agreed to pay, and for what.
 *
 * Brought across from the legacy's 82 rows, every one attached to a project and
 * a plan phase. What those rows settled: currency is real (15 were USD), the
 * status column was dead ('Pending' on all 82), and payment evidence was never
 * once recorded despite two columns for it.
 */
class ContractMilestoneTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha', 'currency' => 'KES']);
        app()->instance('current.organization_id', $this->org->id);

        $this->project = Project::create([
            'organization_id' => $this->org->id, 'name' => 'Employee Portal Revamp',
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

        $employee = Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Test', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function editor(): Employee
    {
        return $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}?org={$this->org->slug}";
    }

    private function task(array $attrs = []): ProjectTask
    {
        return ProjectTask::create($attrs + [
            'organization_id' => $this->org->id, 'project_id' => $this->project->id,
            'title' => 'Phase 1 — Network',
        ]);
    }

    // ── Recording ─────────────────────────────────────────────────────────

    public function test_a_milestone_is_paid_against_a_task(): void
    {
        // Every one of the legacy's 82 pointed at a plan phase; a plan phase is
        // a task here, so they land unchanged.
        $this->editor();
        $task = $this->task();

        $res = $this->postJson($this->url('/milestones'), [
            'name' => 'On delivery of Phase 1', 'value' => 381394.71,
            'project_task_id' => $task->id, 'due_on' => '2026-03-31',
        ])->assertCreated();

        $this->assertSame($task->id, $res->json('data.task.id'));
        $this->assertMatchesRegularExpression('/^MS-\d+$/', $res->json('data.reference'));
    }

    public function test_a_milestone_takes_the_organisations_currency_by_default(): void
    {
        // So a tenant billing in KES does not type it eighty-two times.
        $this->editor();

        $this->postJson($this->url('/milestones'), ['name' => 'Deposit', 'value' => 100000])
            ->assertCreated()->assertJsonPath('data.currency', 'KES');
    }

    public function test_a_milestone_can_be_in_another_currency(): void
    {
        // 15 of the legacy's 82 were USD. A single implicit currency would
        // silently misstate nearly a fifth of them.
        $this->editor();

        $this->postJson($this->url('/milestones'), [
            'name' => 'Licence renewal', 'value' => 15000, 'currency' => 'USD',
        ])->assertCreated()->assertJsonPath('data.currency', 'USD');
    }

    public function test_a_milestone_cannot_be_paid_against_another_projects_work(): void
    {
        $this->editor();
        $other = Project::create(['organization_id' => $this->org->id, 'name' => 'Elsewhere']);
        $theirs = ProjectTask::create([
            'organization_id' => $this->org->id, 'project_id' => $other->id, 'title' => 'Theirs',
        ]);

        $this->postJson($this->url('/milestones'), [
            'name' => 'Wrong', 'value' => 1000, 'project_task_id' => $theirs->id,
        ])->assertStatus(422)->assertJsonValidationErrors('project_task_id');
    }

    // ── The status the legacy never used ──────────────────────────────────

    public function test_invoicing_a_milestone_stamps_when(): void
    {
        $this->editor();
        $m = $this->milestone();

        $res = $this->postJson($this->url("/milestones/{$m->id}/transition"), [
            'status' => 'invoiced', 'invoice_reference' => 'INV-1042',
        ])->assertOk();

        $this->assertNotNull($res->json('data.invoiced_at'));
        $this->assertSame('INV-1042', $res->json('data.invoice_reference'));
        $this->assertTrue($res->json('data.is_outstanding'));
    }

    public function test_paying_keeps_the_invoice_date_and_adds_a_payment_date(): void
    {
        // When it was billed and when it was paid are different facts, and the
        // gap between them is the only thing anybody wants to measure.
        $this->editor();
        $m = $this->milestone();

        $this->postJson($this->url("/milestones/{$m->id}/transition"), ['status' => 'invoiced'])->assertOk();
        $invoicedAt = $m->fresh()->invoiced_at;

        $res = $this->postJson($this->url("/milestones/{$m->id}/transition"), ['status' => 'paid'])->assertOk();

        $this->assertEquals($invoicedAt->toIso8601String(), $res->json('data.invoiced_at'));
        $this->assertNotNull($res->json('data.paid_at'));
        $this->assertTrue($res->json('data.is_paid'));
        // Paid is not outstanding.
        $this->assertFalse($res->json('data.is_outstanding'));
    }

    public function test_reverting_a_milestone_clears_the_dates_it_no_longer_earns(): void
    {
        /*
         * A status that disagrees with its timestamps is what makes a
         * receivables report untrustworthy — it would show a payment date on
         * something no longer paid.
         */
        $this->editor();
        $m = $this->milestone();

        $this->postJson($this->url("/milestones/{$m->id}/transition"), ['status' => 'paid'])->assertOk();
        $res = $this->postJson($this->url("/milestones/{$m->id}/transition"), ['status' => 'pending'])->assertOk();

        $this->assertNull($res->json('data.paid_at'));
        $this->assertNull($res->json('data.invoiced_at'));
    }

    public function test_overdue_is_not_the_same_as_outstanding(): void
    {
        // An invoice issued last week is outstanding but not late, and
        // reporting the two as one number is how the figure stops being
        // trusted.
        $this->editor();

        $soon = $this->milestone(['due_on' => now()->addMonth()->toDateString()]);
        $late = $this->milestone(['due_on' => now()->subMonth()->toDateString()]);

        $this->postJson($this->url("/milestones/{$soon->id}/transition"), ['status' => 'invoiced'])->assertOk();
        $this->postJson($this->url("/milestones/{$late->id}/transition"), ['status' => 'invoiced'])->assertOk();

        $rows = collect($this->getJson($this->url('/milestones'))->assertOk()->json('data'))->keyBy('id');

        $this->assertTrue($rows[$soon->id]['is_outstanding']);
        $this->assertFalse($rows[$soon->id]['is_overdue']);
        $this->assertTrue($rows[$late->id]['is_overdue']);
    }

    public function test_a_waived_milestone_is_never_overdue(): void
    {
        $this->editor();
        $m = $this->milestone(['due_on' => now()->subYear()->toDateString()]);

        $res = $this->postJson($this->url("/milestones/{$m->id}/transition"), ['status' => 'waived'])->assertOk();

        $this->assertFalse($res->json('data.is_overdue'));
    }

    // ── Billing against unfinished work ───────────────────────────────────

    public function test_a_milestone_reports_whether_the_work_it_bills_for_is_done(): void
    {
        /*
         * The reason a milestone points at a task at all. Billing for
         * something not yet delivered is a conversation nobody wants to have
         * with the customer first.
         */
        $this->editor();
        $task = $this->task();
        $m = $this->milestone(['project_task_id' => $task->id]);

        $rows = collect($this->getJson($this->url('/milestones'))->json('data'))->keyBy('id');
        $this->assertFalse($rows[$m->id]['work_is_done']);

        $task->update(['status' => ProjectTask::DONE]);

        $rows = collect($this->getJson($this->url('/milestones'))->json('data'))->keyBy('id');
        $this->assertTrue($rows[$m->id]['work_is_done']);
    }

    public function test_a_milestone_with_no_task_says_so_rather_than_claiming_the_work_is_undone(): void
    {
        // Null, not false. "No work attached" and "work unfinished" are
        // different, and only one of them is a problem to chase.
        $this->editor();
        $m = $this->milestone();

        $rows = collect($this->getJson($this->url('/milestones'))->json('data'))->keyBy('id');

        $this->assertNull($rows[$m->id]['work_is_done']);
    }

    // ── Totals ────────────────────────────────────────────────────────────

    public function test_totals_are_reported_per_currency_not_added_together(): void
    {
        /*
         * Adding KES to USD would state a figure that means nothing, and the
         * whole point of a receivables number is that it is trustworthy.
         */
        $this->editor();

        $a = $this->milestone(['value' => 100000, 'currency' => 'KES']);
        $b = $this->milestone(['value' => 5000, 'currency' => 'USD']);
        $this->postJson($this->url("/milestones/{$a->id}/transition"), ['status' => 'invoiced'])->assertOk();
        $this->postJson($this->url("/milestones/{$b->id}/transition"), ['status' => 'paid'])->assertOk();

        $totals = $this->getJson($this->url('/milestones'))->assertOk()->json('totals');

        $this->assertEquals(100000, $totals['KES']['outstanding']);
        $this->assertEquals(0, $totals['USD']['outstanding']);
        $this->assertEquals(5000, $totals['USD']['paid']);
    }

    // ── Commercials ───────────────────────────────────────────────────────

    public function test_milestone_values_are_gated_through_the_deal(): void
    {
        // A project screen is not a way round the elevation, and a milestone
        // value is exactly the number it exists to protect.
        $account = Account::create(['organization_id' => $this->org->id, 'name' => 'KCB']);
        $deal = Opportunity::create([
            'organization_id' => $this->org->id, 'account_id' => $account->id, 'name' => 'Deal',
        ]);
        $this->project->update(['opportunity_id' => $deal->id]);

        $this->editor();
        $this->milestone(['value' => 381394.71]);

        $res = $this->getJson($this->url('/milestones'))->assertOk();

        $this->assertFalse($res->json('commercials_visible'));
        $this->assertNull($res->json('data.0.value'));
        // Withheld, not omitted — a masked figure and an unrecorded one must
        // not look the same.
        $this->assertArrayHasKey('value', $res->json('data.0'));
        $this->assertNull($res->json('totals'));
    }

    public function test_the_elevation_reveals_them(): void
    {
        $account = Account::create(['organization_id' => $this->org->id, 'name' => 'KCB']);
        $deal = Opportunity::create([
            'organization_id' => $this->org->id, 'account_id' => $account->id, 'name' => 'Deal',
        ]);
        $this->project->update(['opportunity_id' => $deal->id]);

        $this->actor([
            Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE,
            Permission::PIPELINE_VIEW, Permission::PIPELINE_VIEW_COMMERCIALS,
        ]);
        $this->milestone(['value' => 381394.71]);

        $this->getJson($this->url('/milestones'))->assertOk()
            ->assertJsonPath('commercials_visible', true);
    }

    public function test_a_project_with_no_deal_does_not_hide_figures_nobody_can_unhide(): void
    {
        // There is no opportunity to check against, so falling back to the
        // project's own permissions is the only answer that lets the screen
        // work at all.
        $this->editor();
        $this->milestone(['value' => 1000]);

        $this->getJson($this->url('/milestones'))->assertOk()
            ->assertJsonPath('commercials_visible', true);
    }

    private function milestone(array $attrs = []): ProjectContractMilestone
    {
        return ProjectContractMilestone::create($attrs + [
            'organization_id' => $this->org->id,
            'project_id'      => $this->project->id,
            'name'            => 'On delivery',
            'currency'        => 'KES',
            'value'           => 100000,
        ]);
    }
}
