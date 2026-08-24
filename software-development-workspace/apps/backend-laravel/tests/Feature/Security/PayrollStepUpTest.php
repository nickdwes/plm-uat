<?php

namespace Tests\Feature\Security;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Role;
use App\Models\User;
use App\Services\Security\StepUpVerification;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Compensation and pay history behind a fresh proof of identity, not just a
 * permission check — for HR looking at somebody else's, and for somebody
 * looking at their own.
 *
 * What matters most here is not that the endpoint 401s without a code — it is
 * that the figures are never present in the JSON at all until one is entered.
 * A field hidden by the UI is not the same thing as a field the server never
 * sent, and only the second one is real.
 */
class PayrollStepUpTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Konvergenz', 'slug' => 'konvergenz']);
        app()->instance('current.organization_id', $this->org->id);

        $this->employee = Employee::create([
            'employee_number' => 'E1', 'primary_organization_id' => $this->org->id,
            'first_name' => 'James', 'last_name' => 'Kinyanjui', 'employment_type' => 'permanent',
            'date_hired' => '2024-01-01', 'status' => 'active',
            'basic_salary' => 200000, 'house_allowance' => 15000,
        ]);
    }

    private function hrUser(): User
    {
        $user = User::factory()->create(['email' => 'hr@kns.co.ke']);
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'HR', 'scope' => 'organization',
            'permissions' => [Permission::EMPLOYEES_VIEW, Permission::EMPLOYEES_VIEW_SENSITIVE],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        return $user;
    }

    private function elevate(User $user): void
    {
        app(StepUpVerification::class)->verify(
            $user, 'payroll',
            // Bypasses email delivery for tests that only care about what
            // happens once elevation exists — request()+verify() is what
            // StepUpVerificationTest already proves works end to end.
            $this->forceElevate($user),
        );
    }

    /** Requests a real code and hands back the plain value, without asserting on mail. */
    private function forceElevate(User $user): string
    {
        \Illuminate\Support\Facades\Mail::fake();
        $service = app(StepUpVerification::class);
        $service->requestCode($user, 'payroll', null, null);

        $row = \App\Models\Security\SecurityStepUpCode::where('user_id', $user->id)->latest('id')->first();

        // The only place in the whole system a raw code is ever available
        // outside the email itself — reached here by trying every value is
        // not practical, so the test regenerates one the same way and reads
        // it from the outbox instead.
        $captured = null;
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\StepUpCodeMail::class, function ($mail) use (&$captured) {
            preg_match('/letter-spacing:\s*6px[^>]*>(\d{6})</', $mail->render(), $m);
            $captured = $m[1] ?? null;
            return true;
        });

        return $captured;
    }

    private function url(string $path): string
    {
        return "/api/v1/employees/{$this->employee->id}{$path}?org={$this->org->slug}";
    }

    // ── Without a code ────────────────────────────────────────────────────

    public function test_hr_cannot_read_compensation_without_stepping_up(): void
    {
        Sanctum::actingAs($this->hrUser());

        $res = $this->getJson($this->url('/compensation'))->assertStatus(401);

        $this->assertSame('step_up_required', $res->json('code'));
        $this->assertSame('payroll', $res->json('purpose'));
    }

    /** The field must be absent from the JSON, not merely unrendered by the UI. */
    public function test_the_general_employee_read_never_carries_compensation_at_all(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        // Even once fully elevated — this endpoint is not where it lives any more.
        $this->elevate($hr);

        $res = $this->getJson("/api/v1/employees/{$this->employee->id}?org={$this->org->slug}")->assertOk();

        $this->assertArrayNotHasKey('basic_salary', $res->json());
        $this->assertStringNotContainsString('200000', $res->getContent());
    }

    // ── With a code ───────────────────────────────────────────────────────

    public function test_hr_can_read_compensation_once_elevated(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);
        $this->elevate($hr);

        $res = $this->getJson($this->url('/compensation'))->assertOk();

        $this->assertEqualsWithDelta(200000, $res->json('data.basic_salary'), 0.01);
    }

    /** Elevation is not a substitute for the permission — both are required. */
    public function test_elevation_alone_does_not_bypass_the_sensitive_data_permission(): void
    {
        $plain = User::factory()->create(['email' => 'plain@kns.co.ke']);
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Viewer', 'scope' => 'organization',
            'permissions' => [Permission::EMPLOYEES_VIEW],
        ]);
        $plain->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $plain->forgetCachedRoles();

        Sanctum::actingAs($plain);
        $this->elevate($plain);

        $this->getJson($this->url('/compensation'))->assertStatus(403);
    }

    /** "For Self" — an employee viewing their own record needs the same fresh proof. */
    public function test_an_employee_must_also_step_up_to_see_their_own_compensation(): void
    {
        $user = User::factory()->create(['email' => 'james@kns.co.ke']);
        $this->employee->update(['user_id' => $user->id]);

        // Even viewing your own record needs a role granting access to the
        // organisation at all — self-service is not a bypass of that.
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Self service', 'scope' => 'organization',
            'permissions' => [Permission::EMPLOYEES_VIEW_OWN],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        Sanctum::actingAs($user);

        $this->getJson($this->url('/compensation'))->assertStatus(401);

        $this->elevate($user);

        $res = $this->getJson($this->url('/compensation'))->assertOk();
        $this->assertEqualsWithDelta(200000, $res->json('data.basic_salary'), 0.01);
    }

    public function test_payslip_history_needs_the_same_step_up(): void
    {
        Sanctum::actingAs($this->hrUser());

        $this->getJson($this->url('/payslips'))->assertStatus(401);
    }

    /**
     * The regression this exists to catch: the first version of this read
     * joined payroll_run_items to payroll_runs on employee_id, and
     * payroll_run_items has no such column at all — it is one row per
     * earning or deduction line, not one row per employee per run. It 500'd
     * every time it actually ran, which nothing had done before, since HR
     * needs the sensitive permission and self-service is one person testing
     * their own history. A step-up test asserting only the 401 would not have
     * caught it either — this one has to run the query for real.
     */
    public function test_payslip_history_reads_the_real_payroll_run_columns(): void
    {
        $period = PayrollPeriod::create([
            'organization_id' => $this->org->id, 'name' => 'January 2026',
            'start_date' => '2026-01-01', 'end_date' => '2026-01-31',
        ]);
        PayrollRun::create([
            'payroll_period_id' => $period->id, 'employee_id' => $this->employee->id,
            'basic_salary' => 200000, 'gross_pay' => 215000, 'net_pay' => 170000,
            'paye' => 30000, 'shif' => 5912.5, 'nssf_tier1' => 420, 'nssf_tier2' => 1740,
            'status' => 'finalized',
        ]);

        $hr = $this->hrUser();
        Sanctum::actingAs($hr);
        $this->elevate($hr);

        $rows = $this->getJson($this->url('/payslips'))->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(215000, $rows[0]['gross'], 0.01);
        $this->assertEqualsWithDelta(170000, $rows[0]['net'], 0.01);
        $this->assertEqualsWithDelta(2160, $rows[0]['nssf'], 0.01, 'Tier I plus Tier II.');
        $this->assertSame('January 2026', $rows[0]['period']);
    }

    /** Elevation covers every employee's payroll, not just the one it was requested against. */
    public function test_elevation_carries_across_different_employees_pay(): void
    {
        $other = Employee::create([
            'employee_number' => 'E2', 'primary_organization_id' => $this->org->id,
            'first_name' => 'Other', 'last_name' => 'Person', 'employment_type' => 'permanent',
            'date_hired' => '2024-01-01', 'status' => 'active', 'basic_salary' => 90000,
        ]);

        $hr = $this->hrUser();
        Sanctum::actingAs($hr);
        $this->elevate($hr);

        $this->getJson($this->url('/compensation'))->assertOk();
        $this->getJson("/api/v1/employees/{$other->id}/compensation?org={$this->org->slug}")->assertOk();
    }
}
