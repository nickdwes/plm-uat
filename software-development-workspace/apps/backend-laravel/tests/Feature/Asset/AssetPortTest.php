<?php

namespace Tests\Feature\Asset;

use App\Models\Asset\Asset;
use App\Models\Asset\AssetIssue;
use App\Models\Asset\AssetRequest;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Policies\Asset\AssetPolicy;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Assets port.
 *
 * Two things are being protected. That the schema behaves — tenancy holds,
 * uniqueness is per-organization, relations resolve. And that authorization
 * holds — an employee can see the laptop they hold without being able to
 * browse the register, and nothing crosses a tenant boundary.
 *
 * The per-organization uniqueness tests matter most: the source schema had
 * these unique globally, which in a multi-tenant system means one customer's
 * asset tag can block another's.
 */
class AssetPortTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;
    private Organization $orgB;
    private AssetPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA   = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->orgB   = Organization::create(['name' => 'Beta',  'slug' => 'beta']);
        $this->policy = new AssetPolicy();

        app()->instance('current.organization_id', $this->orgA->id);
    }

    private function employee(Organization $org, ?User $user = null): Employee
    {
        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $org->id,
            'first_name'              => fake()->firstName(),
            'last_name'               => fake()->lastName(),
            'employment_type'         => 'permanent',
            'date_hired'              => '2024-01-01',
            'user_id'                 => $user?->id,
        ]);
    }

    private function userWith(array $permissions, ?Organization $org = null): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name'         => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test role',
            'scope'        => 'organization',
            'permissions'  => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => ($org ?? $this->orgA)->id]);
        $user->forgetCachedRoles();

        return $user;
    }

    private function asset(Organization $org, array $attrs = []): Asset
    {
        return Asset::create(array_merge([
            'organization_id' => $org->id,
            'name'            => 'ThinkPad X1',
            'asset_tag'       => 'AST-' . fake()->unique()->numberBetween(1000, 999999),
            'category'        => 'laptop',
            'status'          => Asset::STATUS_AVAILABLE,
        ], $attrs));
    }

    // ── Schema ────────────────────────────────────────────────────────────────

    public function test_an_asset_tag_may_repeat_across_organizations(): void
    {
        // Globally unique in the source schema, which would let one tenant
        // block another's tag.
        $this->asset($this->orgA, ['asset_tag' => 'AST-0001']);
        $other = $this->asset($this->orgB, ['asset_tag' => 'AST-0001']);

        $this->assertNotNull($other->id);
    }

    public function test_an_asset_tag_may_not_repeat_within_an_organization(): void
    {
        $this->asset($this->orgA, ['asset_tag' => 'AST-DUP']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->asset($this->orgA, ['asset_tag' => 'AST-DUP']);
    }

    public function test_the_organization_scope_hides_other_tenants(): void
    {
        $this->asset($this->orgA);
        $this->asset($this->orgB);

        // Context is Alpha.
        $this->assertCount(1, Asset::all());
        $this->assertCount(2, Asset::forGroup()->get());
    }

    public function test_derived_state_is_computed_not_stored(): void
    {
        $expired = $this->asset($this->orgA, ['warranty_expiry_date' => now()->subDay()]);
        $live    = $this->asset($this->orgA, ['warranty_expiry_date' => now()->addYear()]);
        $due     = $this->asset($this->orgA, ['next_maintenance' => now()->subWeek()]);

        $this->assertTrue($expired->warrantyHasExpired());
        $this->assertFalse($live->warrantyHasExpired());
        $this->assertTrue($due->maintenanceIsDue());
        $this->assertFalse($live->maintenanceIsDue());
    }

    public function test_relations_resolve(): void
    {
        $holder = $this->employee($this->orgA);
        $asset  = $this->asset($this->orgA, [
            'assigned_to' => $holder->id,
            'status'      => Asset::STATUS_ASSIGNED,
        ]);

        $this->assertTrue($asset->isAssigned());
        $this->assertSame($holder->id, $asset->holder->id);
    }

    public function test_an_issue_distinguishes_reporter_from_handler(): void
    {
        $reporter = $this->employee($this->orgA);
        $handler  = $this->employee($this->orgA);
        $asset    = $this->asset($this->orgA);

        $issue = AssetIssue::create([
            'organization_id' => $this->orgA->id,
            'ticket_number'   => 'ISS-1',
            'asset_id'        => $asset->id,
            'reported_by'     => $reporter->id,
            'assigned_to'     => $handler->id,
            'issue_type'      => 'hardware',
            'priority'        => 'high',
            'description'     => 'Screen flickers',
        ]);

        $this->assertSame($reporter->id, $issue->reporter->id);
        $this->assertSame($handler->id, $issue->handler->id);
        $this->assertTrue($issue->isOpen());
    }

    public function test_a_request_can_only_be_decided_once(): void
    {
        $requester = $this->employee($this->orgA);

        $request = AssetRequest::create([
            'organization_id' => $this->orgA->id,
            'request_number'  => 'REQ-1',
            'employee_id'     => $requester->id,
            'category'        => 'laptop',
            'reason'          => 'Replacement',
        ]);

        $this->assertTrue($request->isDecidable());

        $request->update(['status' => AssetRequest::STATUS_APPROVED]);

        $this->assertFalse($request->isDecidable());
    }

    // ── Authorization ─────────────────────────────────────────────────────────

    public function test_an_employee_sees_the_asset_they_hold_but_cannot_browse(): void
    {
        $user     = $this->userWith([Permission::ASSETS_VIEW_OWN]);
        $employee = $this->employee($this->orgA, $user);

        $mine   = $this->asset($this->orgA, ['assigned_to' => $employee->id]);
        $theirs = $this->asset($this->orgA, ['assigned_to' => $this->employee($this->orgA)->id]);

        $this->assertTrue($this->policy->view($user, $mine));
        $this->assertFalse($this->policy->view($user, $theirs));
    }

    public function test_broad_view_sees_every_asset_in_the_organization(): void
    {
        $user = $this->userWith([Permission::ASSETS_VIEW]);
        $this->employee($this->orgA, $user);

        $this->assertTrue($this->policy->view($user, $this->asset($this->orgA)));
    }

    public function test_no_permission_reaches_an_asset_in_another_tenant(): void
    {
        // Holds the widest asset permission, in Alpha.
        $user = $this->userWith(['assets.*']);
        $this->employee($this->orgA, $user);

        $foreign = $this->asset($this->orgB);

        $this->assertFalse($this->policy->view($user, $foreign));
        $this->assertFalse($this->policy->update($user, $foreign));
        $this->assertFalse($this->policy->assign($user, $foreign));
        $this->assertFalse($this->policy->delete($user, $foreign));
    }

    public function test_editing_an_asset_does_not_confer_assigning_it(): void
    {
        // Correcting a serial number should not let someone move equipment
        // between people.
        $user = $this->userWith([Permission::ASSETS_UPDATE]);
        $this->employee($this->orgA, $user);
        $asset = $this->asset($this->orgA);

        $this->assertTrue($this->policy->update($user, $asset));
        $this->assertFalse($this->policy->assign($user, $asset));
    }

    public function test_the_holder_may_report_a_fault_without_any_asset_permission(): void
    {
        $user     = $this->userWith([]);
        $employee = $this->employee($this->orgA, $user);
        $mine     = $this->asset($this->orgA, ['assigned_to' => $employee->id]);
        $theirs   = $this->asset($this->orgA);

        $this->assertTrue($this->policy->reportIssue($user, $mine));
        $this->assertFalse($this->policy->reportIssue($user, $theirs));
    }

    public function test_a_disabled_account_reaches_nothing(): void
    {
        $user = $this->userWith(['assets.*']);
        $this->employee($this->orgA, $user);
        $asset = $this->asset($this->orgA);

        $user->account_status = User::STATUS_DISABLED;

        $this->assertFalse($this->policy->view($user, $asset));
        $this->assertFalse($this->policy->update($user, $asset));
        $this->assertFalse($this->policy->create($user));
    }
}
