<?php

namespace Tests\Feature\Procurement;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Procurement\ProcurementApproval;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Procurement\ProcurementSetting;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The finance-governance core: threshold chains, the budget gate, overrides. */
class ProcurementApprovalChainTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        $this->project = Project::create(['organization_id' => $this->org->id, 'name' => 'SD-WAN']);
        $this->seedStages();
    }

    /** The standard ladder the migration seeds for a real org. */
    private function seedStages(): void
    {
        $rows = [
            ['department_head', 1, null],
            ['finance', 2, 50000],
            ['operations', 3, 250000],
            ['executive', 4, 1000000],
        ];
        foreach ($rows as [$stage, $seq, $above]) {
            \App\Models\Procurement\ProcurementApprovalStage::create([
                'organization_id' => $this->org->id, 'stage' => $stage, 'category' => null,
                'sequence' => $seq, 'required_above_amount' => $above, 'currency' => 'KES', 'is_active' => true,
            ]);
        }
    }

    private function actAs(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'T', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    private function allPerms(): array
    {
        return [
            Permission::ASSETS_VIEW, Permission::ASSETS_CREATE, Permission::ASSETS_UPDATE,
            Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE,
            Permission::PROCUREMENT_APPROVE_DEPARTMENT, Permission::PROCUREMENT_APPROVE_FINANCE,
            Permission::PROCUREMENT_APPROVE_OPERATIONS, Permission::PROCUREMENT_APPROVE_EXECUTIVE,
        ];
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/procurement{$path}?org={$this->org->slug}";
    }

    private function makeRequest(float $amount, ?int $projectId = null): ProcurementRequest
    {
        return ProcurementRequest::create([
            'organization_id' => $this->org->id, 'reference' => 'PR-' . fake()->unique()->numberBetween(10000, 99999),
            'context' => $projectId ? 'project' : 'asset', 'project_id' => $projectId,
            'item' => 'Core switch', 'category' => 'hardware', 'quantity' => 1,
            'estimated_cost' => $amount, 'currency' => 'KES',
        ]);
    }

    private function budget(float $amount): void
    {
        $this->project->budgetLines()->create([
            'organization_id' => $this->org->id, 'label' => 'Hardware', 'currency' => 'KES', 'amount' => $amount,
        ]);
    }

    public function test_the_chain_matches_the_amount_band(): void
    {
        $this->actAs($this->allPerms());
        $this->budget(5_000_000);

        $bands = [
            [30_000,    ['department_head']],
            [120_000,   ['department_head', 'finance']],
            [400_000,   ['department_head', 'finance', 'operations']],
            [2_000_000, ['department_head', 'finance', 'operations', 'executive']],
        ];

        foreach ($bands as [$amount, $expected]) {
            $pr = $this->makeRequest($amount, $this->project->id);
            $this->postJson($this->url("/{$pr->id}/submit"))->assertOk();

            $stages = ProcurementApproval::where('procurement_request_id', $pr->id)
                ->orderBy('sequence')->pluck('stage')->all();
            $this->assertSame($expected, $stages, "band {$amount}");
            $this->assertSame('submitted', $pr->fresh()->lifecycle_stage);
        }
    }

    public function test_approvals_walk_in_sequence_and_the_legacy_column_follows(): void
    {
        $this->actAs($this->allPerms());
        $this->budget(1_000_000);
        $pr = $this->makeRequest(120_000, $this->project->id);   // dept head + finance
        $this->postJson($this->url("/{$pr->id}/submit"))->assertOk();

        // First decision clears the department head only.
        $this->postJson($this->url("/{$pr->id}/decision"), ['decision' => 'approve'])->assertOk();
        $this->assertSame('pending', $pr->fresh()->approval_status);
        $this->assertSame('finance', $pr->fresh()->pendingApproval()->stage);

        // Second clears finance → fully approved, legacy column agrees.
        $this->postJson($this->url("/{$pr->id}/decision"), ['decision' => 'approve'])->assertOk();
        $pr->refresh();
        $this->assertSame('approved', $pr->approval_status);
        $this->assertSame('approved', $pr->lifecycle_stage);
    }

    public function test_a_rejection_skips_the_remaining_stages(): void
    {
        $this->actAs($this->allPerms());
        $this->budget(1_000_000);
        $pr = $this->makeRequest(400_000, $this->project->id);   // three stages
        $this->postJson($this->url("/{$pr->id}/submit"))->assertOk();

        $this->postJson($this->url("/{$pr->id}/decision"), ['decision' => 'reject', 'comment' => 'Not now'])->assertOk();

        $pr->refresh();
        $this->assertSame('rejected', $pr->approval_status);
        $this->assertSame('rejected', $pr->lifecycle_stage);
        $this->assertSame(2, ProcurementApproval::where('procurement_request_id', $pr->id)->where('status', 'skipped')->count());
        $this->assertSame(0, ProcurementApproval::where('procurement_request_id', $pr->id)->where('status', 'pending')->count());
    }

    public function test_the_budget_gate_blocks_submission_and_names_the_numbers(): void
    {
        $this->actAs($this->allPerms());
        $this->budget(100_000);
        $pr = $this->makeRequest(180_000, $this->project->id);

        $this->postJson($this->url("/{$pr->id}/submit"))->assertStatus(422);
        $this->assertSame(ProcurementRequest::BUDGET_EXCEEDED, $pr->fresh()->budget_result);
        $this->assertSame(0, ProcurementApproval::where('procurement_request_id', $pr->id)->count());
    }

    public function test_committed_spend_counts_against_the_next_request(): void
    {
        $this->actAs($this->allPerms());
        $this->budget(300_000);

        // First request approved: 200k of the 300k is now promised.
        $first = $this->makeRequest(200_000, $this->project->id);
        $this->postJson($this->url("/{$first->id}/submit"))->assertOk();
        $this->postJson($this->url("/{$first->id}/decision"), ['decision' => 'approve'])->assertOk();
        $this->postJson($this->url("/{$first->id}/decision"), ['decision' => 'approve'])->assertOk();
        $this->assertSame('approved', $first->fresh()->approval_status);

        // Only 100k is left, so a 150k request cannot be submitted.
        $second = $this->makeRequest(150_000, $this->project->id);
        $this->postJson($this->url("/{$second->id}/submit"))->assertStatus(422);
        $this->assertSame(200_000.0, (float) $second->fresh()->budget_committed_amount);
    }

    public function test_finance_may_override_an_over_budget_decision_with_a_justification(): void
    {
        $this->actAs($this->allPerms());
        $this->budget(300_000);
        $pr = $this->makeRequest(100_000, $this->project->id);
        $this->postJson($this->url("/{$pr->id}/submit"))->assertOk();   // fits at submit

        // The budget shrinks underneath it — the gate is checked again at the decision.
        $this->project->expenses()->create([
            'organization_id' => $this->org->id, 'label' => 'Spend', 'currency' => 'KES', 'amount' => 280_000,
        ]);

        $this->postJson($this->url("/{$pr->id}/decision"), ['decision' => 'approve'])->assertStatus(422);

        $this->postJson($this->url("/{$pr->id}/decision"), [
            'decision' => 'approve', 'budget_override_justification' => 'Critical spare, CFO agreed',
        ])->assertOk();

        $this->assertSame(ProcurementRequest::BUDGET_OVERRIDDEN, $pr->fresh()->budget_result);
        $this->assertStringContainsString('Budget override', ProcurementApproval::where('procurement_request_id', $pr->id)->where('status', 'approved')->first()->comment);
    }

    public function test_a_margin_breach_appends_an_executive_stage(): void
    {
        $this->actAs($this->allPerms());
        $this->project->update(['contract_value' => 500_000]);
        ProcurementSetting::forOrganization($this->org->id)->update(['margin_floor_percent' => 20]);
        $this->budget(1_000_000);

        // Cost base 450k breaches the 400k ceiling (500k less a 20% floor).
        $pr = $this->makeRequest(450_000, $this->project->id);
        $this->postJson($this->url("/{$pr->id}/submit"))->assertOk();

        $executive = ProcurementApproval::where('procurement_request_id', $pr->id)->where('stage', 'executive')->first();
        $this->assertNotNull($executive);
        $this->assertTrue($executive->escalated);
    }

    public function test_a_request_with_no_chain_keeps_the_legacy_flat_decision(): void
    {
        $this->actAs($this->allPerms());
        // Never submitted (as OrderGenerator-created requests are), so no chain.
        $pr = $this->makeRequest(75_000, $this->project->id);

        $this->postJson($this->url("/{$pr->id}/decision"), ['approval_status' => 'approved'])->assertOk();

        $this->assertSame('approved', $pr->fresh()->approval_status);
        $this->assertSame(0, ProcurementApproval::where('procurement_request_id', $pr->id)->count());
    }

    public function test_a_non_project_request_passes_the_gate_and_says_why(): void
    {
        $this->actAs($this->allPerms());
        $pr = $this->makeRequest(90_000);   // asset context, no project

        $this->postJson($this->url("/{$pr->id}/submit"))->assertOk();
        $this->assertSame(ProcurementRequest::BUDGET_NONE, $pr->fresh()->budget_result);
    }

    public function test_the_stage_permission_gates_who_may_decide(): void
    {
        $this->actAs($this->allPerms());
        $this->budget(1_000_000);
        $pr = $this->makeRequest(120_000, $this->project->id);
        $this->postJson($this->url("/{$pr->id}/submit"))->assertOk();
        $this->postJson($this->url("/{$pr->id}/decision"), ['decision' => 'approve'])->assertOk();   // dept head done

        // Now at finance — somebody with only department approval may not sign.
        $this->actAs([
            Permission::ASSETS_VIEW, Permission::ASSETS_UPDATE, Permission::PROJECTS_VIEW,
            Permission::PROJECTS_UPDATE, Permission::PROCUREMENT_APPROVE_DEPARTMENT,
        ]);
        $this->postJson($this->url("/{$pr->id}/decision"), ['decision' => 'approve'])->assertStatus(403);
    }
}
