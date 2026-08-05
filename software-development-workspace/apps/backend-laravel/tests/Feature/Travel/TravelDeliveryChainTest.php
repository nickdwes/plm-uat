<?php

namespace Tests\Feature\Travel;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Travel\TravelApprovalStage;
use App\Models\Travel\TravelExpenseCategory;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A delivery is not approved by the requester's line manager.
 *
 * Moving equipment is a finance and operations decision — finance always, and
 * the COO once the consignment is large enough. Routing it through a
 * department head asks somebody to sign for a judgement that was never theirs,
 * and the signature then means nothing.
 */
class TravelDeliveryChainTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Department $department;
    private TravelExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $this->department = Department::create([
            'organization_id' => $this->org->id, 'name' => 'Engineering',
            'lead_employee_id' => $this->employee()->id,
        ]);

        $this->category = TravelExpenseCategory::create([
            'organization_id' => $this->org->id, 'name' => 'Transport',
        ]);

        // The default chain: line manager, then finance.
        TravelApprovalStage::insert([
            ['organization_id' => $this->org->id, 'stage' => 'department_head', 'travel_type' => null,
             'sequence' => 1, 'required_above_amount' => null, 'is_active' => true, 'currency' => 'KES',
             'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $this->org->id, 'stage' => 'finance', 'travel_type' => null,
             'sequence' => 3, 'required_above_amount' => null, 'is_active' => true, 'currency' => 'KES',
             'created_at' => now(), 'updated_at' => now()],
        ]);

        // Deliveries: finance always, and the COO above 100,000.
        //
        // Every row carries the same keys — a bulk insert with differing keys
        // is rejected outright by Postgres.
        TravelApprovalStage::insert([
            ['organization_id' => $this->org->id, 'stage' => 'finance', 'travel_type' => 'delivery',
             'sequence' => 1, 'required_above_amount' => null, 'is_active' => true, 'currency' => 'KES',
             'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $this->org->id, 'stage' => 'operations_executive', 'travel_type' => 'delivery',
             'sequence' => 2, 'required_above_amount' => 100000, 'is_active' => true, 'currency' => 'KES',
             'created_at' => now(), 'updated_at' => now()],
        ]);
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

    private function actingAsWith(array $permissions): Employee
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        $employee = $this->employee();
        $employee->update(['user_id' => $user->id, 'department_id' => $this->department->id]);
        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/travel-requests{$path}?org={$this->org->slug}";
    }

    /** Raise a request of a given type, with one costed line. */
    private function raise(string $type, float $amount): int
    {
        $traveller = $this->actingAsWith([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN]);

        $id = $this->postJson($this->url(), [
            'employee_id'    => $traveller->id,
            'travel_type'    => $type,
            'departure_date' => '2026-09-01',
            'return_date'    => '2026-09-02',
        ])->assertCreated()->json('data.id');

        $this->postJson($this->url("/{$id}/items"), [
            'travel_expense_category_id' => $this->category->id,
            'amount_claimed'             => $amount,
        ])->assertOk();

        return $id;
    }

    private function stagesOn(int $id): array
    {
        return collect(
            $this->postJson($this->url("/{$id}/submit"))->assertOk()->json('data.approvals')
        )->pluck('stage')->all();
    }

    // ── The chain that applies ────────────────────────────────────────────

    public function test_an_ordinary_trip_uses_the_default_chain(): void
    {
        $this->assertSame(
            ['department_head', 'finance'],
            $this->stagesOn($this->raise('local', 20000)),
        );
    }

    public function test_a_delivery_skips_the_line_manager_entirely(): void
    {
        // Not "also finance" — instead of the department head. Moving
        // equipment was never their decision to make.
        $this->assertSame(
            ['finance'],
            $this->stagesOn($this->raise('delivery', 20000)),
        );
    }

    public function test_a_large_consignment_adds_the_coo(): void
    {
        $this->assertSame(
            ['finance', 'operations_executive'],
            $this->stagesOn($this->raise('delivery', 250000)),
        );
    }

    public function test_a_delivery_below_the_threshold_still_needs_approval(): void
    {
        // A threshold decides which stages also apply, never whether a request
        // is approved at all.
        $stages = $this->stagesOn($this->raise('delivery', 5000));

        $this->assertNotEmpty($stages, 'Every request goes through the chain.');
        $this->assertSame(['finance'], $stages);
    }

    public function test_a_type_with_no_chain_of_its_own_falls_back_to_the_default(): void
    {
        // International has no specific chain configured here. Falling through
        // to the default is better than leaving it with no approvers.
        $this->assertSame(
            ['department_head', 'finance'],
            $this->stagesOn($this->raise('international', 30000)),
        );
    }

    // ── Who may act ───────────────────────────────────────────────────────

    public function test_the_coo_stage_needs_its_own_permission(): void
    {
        // Signing off a consignment is an operations judgement, not a
        // financial one, and the CFO's authority does not cover it.
        $id = $this->raise('delivery', 250000);
        $this->stagesOn($id);

        $this->actingAsWith([Permission::TRAVEL_VIEW, Permission::TRAVEL_ASSESS_FINANCE]);
        $this->postJson($this->url("/{$id}/decision"), ['decision' => 'approve'])->assertOk();

        // Finance is done; the COO stage is now pending and finance cannot
        // take it.
        $this->postJson($this->url("/{$id}/decision"), ['decision' => 'approve'])->assertForbidden();

        $this->actingAsWith([Permission::TRAVEL_VIEW, Permission::TRAVEL_APPROVE_OPERATIONS]);
        $this->postJson($this->url("/{$id}/decision"), ['decision' => 'approve'])->assertOk();
    }

    public function test_approving_travel_does_not_confer_signing_for_a_consignment(): void
    {
        $id = $this->raise('delivery', 250000);
        $this->stagesOn($id);

        $this->actingAsWith([Permission::TRAVEL_VIEW, Permission::TRAVEL_APPROVE, Permission::TRAVEL_APPROVE_EXECUTIVE]);

        $this->postJson($this->url("/{$id}/decision"), ['decision' => 'approve'])->assertForbidden();
    }
}
