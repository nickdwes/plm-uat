<?php

namespace Tests\Feature\Authorization;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The modules that used to check nothing, or checked role names.
 *
 * Benefits had seventeen public methods anyone signed in could call, including
 * deleting a plan. The roster asked `isOrgHR()` / `isManagerIn()` by name, so
 * no custom role could ever reach it. These pin both onto the permission
 * catalogue, which is what makes them administrable from the role editor.
 */
class EnforcementGapsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        DefaultReferenceData::seedFor($this->org->id);
    }

    private function actor(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id, 'first_name' => 'T', 'last_name' => 'P',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01', 'user_id' => $user->id,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function url(string $path): string
    {
        return "/api/v1{$path}?org={$this->org->slug}";
    }

    // ── The client's copy of the caller's rights ──────────────────────────

    public function test_my_permissions_agrees_with_the_engine_on_the_three_way_case(): void
    {
        /*
         * Wildcard grant, per-role exclusion, per-user deny — the three layers
         * that decide a permission. The list the client receives must agree
         * with hasPermission() on every one, or the UI shows doors the API
         * keeps locked.
         */
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization',
            'permissions' => ['leave.*', 'payroll.view'],
            'excluded_permissions' => ['leave.approve'],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        \Illuminate\Support\Facades\DB::table('deny_assignments')->insert([
            'user_id' => $user->id, 'organization_id' => $this->org->id,
            'permission' => 'payroll.view', 'reason' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        $list = $this->getJson($this->url('/authorization/my-permissions'))
            ->assertOk()->json('data.permissions');

        // The wildcard expanded to concrete strings…
        $this->assertContains('leave.submit', $list);
        $this->assertContains('leave.view', $list);
        // …minus the per-role exclusion…
        $this->assertNotContains('leave.approve', $list);
        // …and the per-user deny.
        $this->assertNotContains('payroll.view', $list);
        // Nothing raw leaks: every entry is a concrete catalogue string.
        $this->assertNotContains('leave.*', $list);

        // And every entry round-trips through the engine itself.
        foreach ($list as $permission) {
            $this->assertTrue($user->hasPermission($permission), "listed but refused: {$permission}");
        }
    }

    public function test_login_hands_the_client_its_permissions(): void
    {
        $user = User::factory()->create(['password' => \Illuminate\Support\Facades\Hash::make('correct-horse-battery')]);
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => ['leave.view_own'],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);

        $res = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])
            ->assertOk();

        $this->assertContains('leave.view_own', $res->json('permissions'));
    }

    // ── Benefits ──────────────────────────────────────────────────────────

    public function test_the_benefits_catalogue_is_no_longer_open_to_everyone(): void
    {
        $this->actor([]);

        $this->getJson($this->url('/benefits/definitions'))->assertForbidden();
    }

    public function test_reading_benefits_needs_view_and_changing_them_needs_manage(): void
    {
        $this->actor([Permission::BENEFITS_VIEW]);

        $this->getJson($this->url('/benefits/definitions'))->assertOk();

        // View is not manage: reading the catalogue must not let you add to it.
        $this->postJson($this->url('/benefits/definitions'), [
            'name' => 'Gym', 'benefit_type' => 'wellness',
        ])->assertForbidden();
    }

    public function test_a_wildcard_grant_reaches_benefits(): void
    {
        // The point of moving onto the catalogue: `benefits.*` in a role is
        // enough, with no code knowing which role that is.
        $this->actor(['benefits.*']);

        $this->getJson($this->url('/benefits/definitions'))->assertOk();
    }

    public function test_deciding_a_benefit_approval_is_its_own_tier(): void
    {
        // A real approval row, because route binding 404s a missing one
        // before any guard runs — the tier can only be proven on a record.
        $user = $this->actor([Permission::BENEFITS_VIEW, Permission::BENEFITS_MANAGE]);

        $definitionId = \Illuminate\Support\Facades\DB::table('benefit_definitions')->insertGetId([
            'organization_id' => $this->org->id, 'name' => 'Medical', 'benefit_type' => 'medical',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $approvalId = \Illuminate\Support\Facades\DB::table('benefit_approvals')->insertGetId([
            'employee_id' => $user->employee->id, 'benefit_definition_id' => $definitionId,
            'requested_date' => now()->toDateString(), 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Holding manage does not decide approvals.
        $this->patchJson($this->url("/benefits/approvals/{$approvalId}/action"), ['action' => 'approve'])
            ->assertForbidden();
    }

    // ── Roster ────────────────────────────────────────────────────────────

    public function test_the_roster_is_reachable_by_permission_not_role_name(): void
    {
        // A custom role with roster.view — a thing that was impossible when
        // the controllers asked isManagerIn() by name.
        $this->actor([Permission::ROSTER_VIEW]);

        $this->getJson($this->url('/roster'))->assertOk();
    }

    public function test_reading_the_roster_does_not_configure_it(): void
    {
        $this->actor([Permission::ROSTER_VIEW, Permission::ROSTER_MANAGE]);

        $this->getJson($this->url('/roster/config'))->assertForbidden();
    }

    public function test_the_schedule_lifecycle_needs_publish(): void
    {
        $this->actor([Permission::ROSTER_PUBLISH]);

        // Successful, not strictly 200: the config row is created on first
        // read, and Laravel answers 201 for a freshly created resource.
        $this->getJson($this->url('/roster/config'))->assertSuccessful();
    }

    // ── HR dashboard and training assignments ─────────────────────────────

    public function test_the_hr_dashboard_follows_the_employee_register(): void
    {
        $this->actor([]);
        $this->getJson($this->url('/dashboard'))->assertForbidden();

        $this->actor([Permission::EMPLOYEES_VIEW]);
        $this->getJson($this->url('/dashboard'))->assertOk();
    }

    public function test_bulk_assigning_training_needs_manage(): void
    {
        /*
         * Valid payload on purpose: the FormRequest validates before the
         * in-method guard, so garbage 422s without ever reaching it. The
         * guard is what must answer when the data itself is fine.
         */
        $user = $this->actor([Permission::TRAINING_VIEW]);

        $itemId = \Illuminate\Support\Facades\DB::table('training_items')->insertGetId([
            'organization_id' => $this->org->id, 'title' => 'Fire safety',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson($this->url('/training/assignments'), [
            'training_item_id' => (string) $itemId,
            'employee_ids'     => [$user->employee->id],
            'assignment_type'  => 'mandatory',
        ])->assertForbidden();
    }

    public function test_my_pending_training_is_self_service(): void
    {
        // Reading your own queue needs no permission at all.
        $this->actor([]);

        $this->getJson($this->url('/training/assignments/my-pending'))->assertOk();
    }

    // ── The payroll admin surface is not the self-service surface ─────────

    public function test_payslip_view_own_does_not_open_the_payroll_admin_surface(): void
    {
        /*
         * The hole the increment-6 sweep found: PayrollPeriodPolicy::view
         * admitted payslip.view_own while the endpoints behind it never
         * narrowed to self — so every base employee could read any
         * colleague's slip, the bank payment files and the whole P9 by id.
         * Self-service payslips live at /employees/{id}/payslips instead.
         */
        $this->actor(['payslip.view_own']);

        $this->getJson($this->url('/payroll/p9'))->assertForbidden();
        $this->getJson($this->url('/payroll/p9/reports'))->assertForbidden();
        $this->getJson($this->url('/payroll/1/slip/1'))->assertForbidden();
        $this->getJson($this->url('/payroll/1/payment-file'))->assertForbidden();

        // The payroll permission itself still opens the surface.
        $this->actor([\App\Support\Authorization\Permission::PAYROLL_VIEW]);
        $this->getJson($this->url('/payroll/p9'))->assertOk();
    }

    // ── The team width on the pipeline ────────────────────────────────────

    public function test_view_team_reaches_direct_reports_leads_and_no_further(): void
    {
        $manager = $this->actor([\App\Support\Authorization\Permission::PIPELINE_VIEW_TEAM]);

        $report = Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id, 'first_name' => 'R', 'last_name' => 'D',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'reports_to' => $manager->employee->id,
        ]);
        $peer = Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id, 'first_name' => 'P', 'last_name' => 'R',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);

        $mine   = $this->makeLead('Mine', $manager->employee->id);
        $theirs = $this->makeLead('Report lead', $report->id);
        $other  = $this->makeLead('Peer lead', $peer->id);

        $names = array_column(
            $this->getJson($this->url('/leads'))->assertOk()->json('data'),
            'name',
        );

        // Mine and my direct report's — the peer's book stays theirs.
        $this->assertContains('Mine', $names);
        $this->assertContains('Report lead', $names);
        $this->assertNotContains('Peer lead', $names);

        // The record a manager can list is exactly one they can open.
        $this->getJson($this->url("/leads/{$theirs->id}"))->assertOk();
        $this->getJson($this->url("/leads/{$other->id}"))->assertForbidden();
    }

    private function makeLead(string $name, int $ownerId): \App\Models\Pipeline\Lead
    {
        return \App\Models\Pipeline\Lead::create([
            'organization_id' => $this->org->id, 'name' => $name,
            'status' => 'open', 'owner_id' => $ownerId,
        ]);
    }
}
