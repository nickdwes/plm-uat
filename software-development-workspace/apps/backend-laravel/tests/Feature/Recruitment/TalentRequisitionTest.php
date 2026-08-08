<?php

namespace Tests\Feature\Recruitment;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\Recruitment\RequisitionApprovalPolicy;
use App\Models\Recruitment\TalentRequisition;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Asking for a role, and the decision on it.
 *
 * The chain is the feature. A role costs money and headcount, so somebody asks
 * and two or three people agree — and the order they agree in is the whole
 * point, because a CTO approving something HR has not seen is not a chain.
 */
class TalentRequisitionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $this->department = Department::create([
            'organization_id' => $this->org->id, 'name' => 'Product Development',
        ]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function employee(string $first = 'Test', ?int $reportsTo = null, bool $withLogin = true): Employee
    {
        $employee = Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => $first, 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'reports_to' => $reportsTo,
        ]);

        if ($withLogin) {
            $employee->update(['user_id' => User::factory()->create()->id]);
        }

        return $employee->fresh();
    }

    private function signIn(Employee $employee, array $permissions): Employee
    {
        $user = $employee->user ?? User::factory()->create();

        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        $employee->update(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    /**
     * The designs' chain: line manager, then HR, then an executive.
     *
     * Built as an organisation default unless a department is named, which is
     * the fallback the policy lookup is for.
     */
    private function policy(array $employees, ?int $departmentId = null): RequisitionApprovalPolicy
    {
        $policy = RequisitionApprovalPolicy::create([
            'organization_id' => $this->org->id,
            'department_id'   => $departmentId,
            'name' => $departmentId ? 'Product Development' : 'Default',
        ]);

        foreach ($employees as $i => [$label, $employee]) {
            $policy->steps()->create([
                'position' => $i + 1, 'label' => $label,
                'resolver' => 'employee', 'employee_id' => $employee->id,
            ]);
        }

        return $policy->fresh('steps');
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/requisitions{$path}?org={$this->org->slug}";
    }

    private function raise(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->url(), $body + [
            'job_title' => 'Senior Product Designer',
            'department_id' => $this->department->id,
            'positions' => 2, 'priority' => 'high',
        ]);
    }

    // ── Raising one ───────────────────────────────────────────────────────

    public function test_a_requisition_is_numbered_by_year(): void
    {
        $hr = $this->employee('HR');
        $this->policy([['HR', $hr]]);
        $this->signIn($this->employee('Asker'), [Permission::REQUISITIONS_CREATE]);

        $this->raise()->assertCreated()
            ->assertJsonPath('data.reference', 'Req-' . now()->format('Y') . '-0001')
            ->assertJsonPath('data.status', 'in_approval');
    }

    public function test_references_do_not_repeat(): void
    {
        $this->policy([['HR', $this->employee('HR')]]);
        $this->signIn($this->employee('Asker'), [Permission::REQUISITIONS_CREATE]);

        $this->raise()->assertCreated();
        $this->raise()->assertCreated()
            ->assertJsonPath('data.reference', 'Req-' . now()->format('Y') . '-0002');
    }

    /**
     * A requisition nobody has to agree to is a vacancy with extra steps.
     * Refusing is honest; auto-approving would make the control look present
     * while doing nothing.
     */
    public function test_a_department_with_no_chain_cannot_raise_one(): void
    {
        $this->signIn($this->employee('Asker'), [Permission::REQUISITIONS_CREATE]);

        $this->raise()->assertJsonValidationErrorFor('department_id');
        $this->assertSame(0, TalentRequisition::count());
    }

    /** A department's own chain wins over the organisation's default. */
    public function test_a_department_chain_overrides_the_default(): void
    {
        $default = $this->employee('Default approver');
        $special = $this->employee('Department approver');

        $this->policy([['Exec', $default]]);
        $this->policy([['Head of Product', $special]], $this->department->id);

        $this->signIn($this->employee('Asker'), [Permission::REQUISITIONS_CREATE]);

        $this->raise()->assertCreated()
            ->assertJsonPath('data.approvals.0.label', 'Head of Product')
            ->assertJsonPath('data.approvals.0.approver.name', 'Department approver Person');
    }

    /**
     * Worked out from whoever asked, so it survives a reorganisation — naming
     * a manager explicitly means every restructure silently breaks the chain.
     */
    public function test_a_line_manager_step_resolves_from_the_requester(): void
    {
        $manager = $this->employee('Manager');
        $asker   = $this->employee('Asker', reportsTo: $manager->id);

        $policy = RequisitionApprovalPolicy::create([
            'organization_id' => $this->org->id, 'name' => 'Default',
        ]);
        $policy->steps()->create(['position' => 1, 'label' => 'Line manager', 'resolver' => 'line_manager']);

        $this->signIn($asker, [Permission::REQUISITIONS_CREATE]);

        $this->raise()->assertCreated()
            ->assertJsonPath('data.approvals.0.approver.name', 'Manager Person');
    }

    /** A step nobody fills is shown, not silently skipped. */
    public function test_a_step_with_nobody_to_fill_it_is_flagged(): void
    {
        $policy = RequisitionApprovalPolicy::create([
            'organization_id' => $this->org->id, 'name' => 'Default',
        ]);
        // The requester has no manager, so this rung resolves to nobody.
        $policy->steps()->create(['position' => 1, 'label' => 'Line manager', 'resolver' => 'line_manager']);

        $this->signIn($this->employee('Asker'), [Permission::REQUISITIONS_CREATE]);

        $this->raise()->assertCreated()->assertJsonPath('data.approvals.0.unassigned', true);
    }

    /** The chain is copied at submission, not read through the policy. */
    public function test_rewriting_the_policy_does_not_rewrite_a_decided_chain(): void
    {
        $hr = $this->employee('HR');
        $policy = $this->policy([['HR', $hr]]);
        $this->signIn($this->employee('Asker'), [Permission::REQUISITIONS_CREATE]);

        $id = $this->raise()->assertCreated()->json('data.id');

        $policy->steps()->update(['label' => 'People Team']);

        $this->getJson($this->url('/' . $id))->assertOk()->assertJsonPath('data.approvals.0.label', 'HR');
    }

    // ── The chain ─────────────────────────────────────────────────────────

    /**
     * Sequential. A CTO approving something HR has not seen is not a chain, it
     * is three people with opinions.
     */
    public function test_only_the_current_step_can_decide(): void
    {
        $hr  = $this->employee('HR');
        $cto = $this->employee('CTO');
        $this->policy([['HR', $hr], ['CTO', $cto]]);

        $this->signIn($this->employee('Asker'), [Permission::REQUISITIONS_CREATE]);
        $id = $this->raise()->assertCreated()->json('data.id');

        $this->signIn($cto, [Permission::REQUISITIONS_VIEW]);
        $this->postJson($this->url("/{$id}/decide"), ['decision' => 'approved'])->assertForbidden();

        $this->signIn($hr, [Permission::REQUISITIONS_VIEW]);
        $this->postJson($this->url("/{$id}/decide"), ['decision' => 'approved'])
            ->assertOk()
            // Still in approval — the second rung has not been reached yet.
            ->assertJsonPath('data.status', 'in_approval')
            ->assertJsonPath('data.awaiting.label', 'CTO');
    }

    public function test_the_last_approval_approves_the_requisition(): void
    {
        $hr  = $this->employee('HR');
        $cto = $this->employee('CTO');
        $this->policy([['HR', $hr], ['CTO', $cto]]);

        $this->signIn($this->employee('Asker'), [Permission::REQUISITIONS_CREATE]);
        $id = $this->raise()->assertCreated()->json('data.id');

        $this->signIn($hr, [Permission::REQUISITIONS_VIEW]);
        $this->postJson($this->url("/{$id}/decide"), ['decision' => 'approved'])->assertOk();

        $this->signIn($cto, [Permission::REQUISITIONS_VIEW]);
        $this->postJson($this->url("/{$id}/decide"), ['decision' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.is_approved', true);
    }

    /** The server answers who may decide; the client does not compare ids. */
    public function test_only_the_current_approver_is_told_they_can_decide(): void
    {
        $hr = $this->employee('HR');
        $this->policy([['HR', $hr]]);

        $asker = $this->signIn($this->employee('Asker'), [Permission::REQUISITIONS_CREATE]);
        $id = $this->raise()->assertCreated()->json('data.id');

        $this->getJson($this->url('/' . $id))->assertOk()->assertJsonPath('data.can_decide', false);

        $this->signIn($hr, [Permission::REQUISITIONS_VIEW]);
        $this->getJson($this->url('/' . $id))->assertOk()->assertJsonPath('data.can_decide', true);
    }

    // ── Holding and declining ─────────────────────────────────────────────

    /** Both are answers somebody owes. */
    public function test_holding_or_declining_without_a_reason_is_refused(): void
    {
        $hr = $this->employee('HR');
        $this->policy([['HR', $hr]]);

        $this->signIn($this->employee('Asker'), [Permission::REQUISITIONS_CREATE]);
        $id = $this->raise()->assertCreated()->json('data.id');

        $this->signIn($hr, [Permission::REQUISITIONS_VIEW]);

        $this->postJson($this->url("/{$id}/decide"), ['decision' => 'held'])
            ->assertJsonValidationErrorFor('reason');
        $this->postJson($this->url("/{$id}/decide"), ['decision' => 'declined'])
            ->assertJsonValidationErrorFor('reason');
    }

    /**
     * A hold pauses with the step still answerable; a decline ends it.
     * Collapsing the two would make every paused requisition read as a refusal.
     */
    public function test_a_hold_can_be_resumed_and_goes_back_to_whoever_held_it(): void
    {
        $hr = $this->employee('HR');
        $this->policy([['HR', $hr]]);

        $asker = $this->signIn($this->employee('Asker'), [Permission::REQUISITIONS_CREATE]);
        $id = $this->raise()->assertCreated()->json('data.id');

        $this->signIn($hr, [Permission::REQUISITIONS_VIEW]);
        $this->postJson($this->url("/{$id}/decide"), [
            'decision' => 'held', 'reason' => 'Waiting on the budget sign-off.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'on_hold')
            ->assertJsonPath('data.decision_reason', 'Waiting on the budget sign-off.');

        $this->signIn($asker, [Permission::REQUISITIONS_CREATE]);
        $this->postJson($this->url("/{$id}/resume"))
            ->assertOk()
            ->assertJsonPath('data.status', 'in_approval')
            // Back to the person who asked the question, not past them.
            ->assertJsonPath('data.awaiting.label', 'HR');
    }

    public function test_a_decline_ends_the_chain(): void
    {
        $hr = $this->employee('HR');
        $this->policy([['HR', $hr]]);

        $this->signIn($this->employee('Asker'), [Permission::REQUISITIONS_CREATE]);
        $id = $this->raise()->assertCreated()->json('data.id');

        $this->signIn($hr, [Permission::REQUISITIONS_VIEW]);
        $this->postJson($this->url("/{$id}/decide"), [
            'decision' => 'declined', 'reason' => 'Hiring freeze this quarter.',
        ])->assertOk()->assertJsonPath('data.status', 'declined');

        $this->postJson($this->url("/{$id}/decide"), ['decision' => 'approved'])->assertStatus(422);
    }

    // ── The timeline ──────────────────────────────────────────────────────

    /**
     * Rows rather than columns, because the same stage can be reached twice —
     * "held twice before approval" is what somebody reviewing a slow hire needs.
     */
    public function test_the_timeline_records_every_stage_including_repeats(): void
    {
        $hr = $this->employee('HR');
        $this->policy([['HR', $hr]]);

        $asker = $this->signIn($this->employee('Asker'), [Permission::REQUISITIONS_CREATE]);
        $id = $this->raise()->assertCreated()->json('data.id');

        $this->signIn($hr, [Permission::REQUISITIONS_VIEW]);
        $this->postJson($this->url("/{$id}/decide"), ['decision' => 'held', 'reason' => 'Budget.'])->assertOk();

        $this->signIn($asker, [Permission::REQUISITIONS_CREATE]);
        $this->postJson($this->url("/{$id}/resume"))->assertOk();

        $this->signIn($hr, [Permission::REQUISITIONS_VIEW]);
        $stages = collect($this->postJson($this->url("/{$id}/decide"), ['decision' => 'approved'])
            ->assertOk()->json('data.timeline'))->pluck('status')->all();

        $this->assertSame(
            ['requested', 'in_approval', 'on_hold', 'in_approval', 'approved'],
            $stages,
        );
    }

    // ── Being told ────────────────────────────────────────────────────────

    public function test_the_approver_is_told_it_is_their_turn(): void
    {
        $hr = $this->employee('HR');
        $this->policy([['HR', $hr]]);

        $this->signIn($this->employee('Asker'), [Permission::REQUISITIONS_CREATE]);
        $this->raise()->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $hr->user_id, 'type' => 'requisition.awaiting_approval',
        ]);
    }

    public function test_the_requester_is_told_the_outcome(): void
    {
        $hr = $this->employee('HR');
        $this->policy([['HR', $hr]]);

        $asker = $this->signIn($this->employee('Asker'), [Permission::REQUISITIONS_CREATE]);
        $id = $this->raise()->assertCreated()->json('data.id');

        $this->signIn($hr, [Permission::REQUISITIONS_VIEW]);
        $this->postJson($this->url("/{$id}/decide"), [
            'decision' => 'declined', 'reason' => 'Hiring freeze.',
        ])->assertOk();

        $notification = Notification::where('user_id', $asker->user_id)
            ->where('type', 'requisition.declined')->firstOrFail();

        $this->assertSame('Hiring freeze.', $notification->message);
    }

    // ── The list ──────────────────────────────────────────────────────────

    /** A tab showing eleven rows above a card saying ten cannot happen. */
    public function test_the_counts_match_the_list(): void
    {
        $hr = $this->employee('HR');
        $this->policy([['HR', $hr]]);
        $this->signIn($this->employee('Asker'), [Permission::REQUISITIONS_VIEW, Permission::REQUISITIONS_CREATE]);

        $this->raise()->assertCreated();
        $this->raise(['job_title' => 'UX Researcher'])->assertCreated();

        $this->getJson($this->url('/summary'))
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('in_approval', 2)
            ->assertJsonPath('open', 0);

        $this->getJson($this->url() . '&status=in_approval')->assertOk()->assertJsonCount(2, 'data');
    }

    /** Somebody with neither a stake nor the permission sees nothing. */
    public function test_a_bystander_sees_no_requisitions(): void
    {
        $hr = $this->employee('HR');
        $this->policy([['HR', $hr]]);

        $this->signIn($this->employee('Asker'), [Permission::REQUISITIONS_CREATE]);
        $this->raise()->assertCreated();

        $this->signIn($this->employee('Bystander'), [Permission::REQUISITIONS_CREATE]);
        $this->getJson($this->url())->assertOk()->assertJsonCount(0, 'data');
    }

    /** The requester and the approvers both keep sight of it. */
    public function test_the_requester_and_approvers_see_their_own(): void
    {
        $hr = $this->employee('HR');
        $this->policy([['HR', $hr]]);

        $asker = $this->signIn($this->employee('Asker'), [Permission::REQUISITIONS_CREATE]);
        $this->raise()->assertCreated();

        $this->getJson($this->url())->assertOk()->assertJsonCount(1, 'data');

        $this->signIn($hr, [Permission::REQUISITIONS_CREATE]);
        $this->getJson($this->url())->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->url('/inbox'))->assertOk()->assertJsonCount(1, 'data');
    }

    // ── Editing ───────────────────────────────────────────────────────────

    /**
     * Editing what is in front of somebody would mean approving one thing and
     * advertising another.
     */
    public function test_a_requisition_in_approval_cannot_be_edited_by_its_requester(): void
    {
        $hr = $this->employee('HR');
        $this->policy([['HR', $hr]]);

        $this->signIn($this->employee('Asker'), [Permission::REQUISITIONS_CREATE]);
        $id = $this->raise()->assertCreated()->json('data.id');

        $this->putJson($this->url('/' . $id), ['job_title' => 'Something else'])->assertForbidden();
    }

    public function test_a_declined_requisition_can_be_edited_and_resubmitted(): void
    {
        $hr = $this->employee('HR');
        $this->policy([['HR', $hr]]);

        $asker = $this->signIn($this->employee('Asker'), [Permission::REQUISITIONS_CREATE]);
        $id = $this->raise()->assertCreated()->json('data.id');

        $this->signIn($hr, [Permission::REQUISITIONS_VIEW]);
        $this->postJson($this->url("/{$id}/decide"), ['decision' => 'declined', 'reason' => 'Too senior.'])
            ->assertOk();

        $this->signIn($asker, [Permission::REQUISITIONS_CREATE]);
        $this->putJson($this->url('/' . $id), [
            'job_title' => 'Product Designer', 'department_id' => $this->department->id,
        ])->assertOk();

        $this->postJson($this->url("/{$id}/submit"))
            ->assertOk()
            ->assertJsonPath('data.status', 'in_approval')
            // A fresh chain, not the old decision left standing.
            ->assertJsonPath('data.approvals.0.status', 'pending');
    }
}
