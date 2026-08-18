<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Role;
use App\Models\User;
use App\Services\Payroll\P10Report;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The employer's monthly PAYE return.
 *
 * Two things have to hold. The figures must be the ones already on the
 * payslips — a return and a payslip disagreeing about one person's tax ends in
 * an audit rather than a correction. And nobody without a KRA PIN may be in it
 * quietly, because iTax rejects the whole sheet rather than the row.
 */
class P10ReportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private PayrollPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $this->period = PayrollPeriod::create([
            'organization_id' => $this->org->id,
            'name' => 'August 2026', 'start_date' => '2026-08-01',
            'end_date' => '2026-08-31', 'payment_date' => '2026-08-28',
            'status' => PayrollPeriod::STATUS_APPROVED,
        ]);
    }

    private function employed(array $attrs = [], array $figures = []): Employee
    {
        $employee = Employee::create(array_merge([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Grace', 'last_name' => 'Njoroge',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'national_id' => (string) fake()->unique()->numberBetween(10000000, 99999999),
            'tax_id' => 'A00' . fake()->unique()->numberBetween(100000, 999999) . 'X',
        ], $attrs));

        PayrollRun::create(array_merge([
            'payroll_period_id' => $this->period->id,
            'employee_id'       => $employee->id,
            'basic_salary' => 200000, 'gross_pay' => 200000,
            'taxable_income' => 187180, 'allowable_deductions' => 12820,
            'paye' => 50936.80, 'paye_payable' => 48536.80,
            'shif' => 5500, 'nssf_tier1' => 480, 'nssf_tier2' => 3840,
            'housing_levy' => 3000, 'personal_relief' => 2400, 'insurance_relief' => 0,
            'total_deductions' => 61356.80, 'net_pay' => 138643.20,
            'status' => 'computed',
        ], $figures));

        return $employee;
    }

    private function report(): array
    {
        return app(P10Report::class)->forPeriod($this->period);
    }

    // ── The figures are the stored ones ───────────────────────────────────────

    /**
     * Chargeable pay is what the engine taxed, not a fresh subtraction. The P9
     * once derived its own and disagreed with the PAYE beside it.
     */
    public function test_the_return_uses_the_figures_the_payslip_used(): void
    {
        $this->employed();

        $row = $this->report()['rows'][0];

        $this->assertSame(187180.0, $row['chargeable_pay']);
        $this->assertSame(12820.0, $row['allowable_deductions']);
        $this->assertSame(50936.80, $row['tax_on_chargeable']);
        $this->assertSame(2400.0, $row['personal_relief']);
        $this->assertSame(48536.80, $row['paye_payable']);
    }

    /** Tax charged less relief is what is remitted, and the file must say so. */
    public function test_tax_less_relief_reconciles_to_the_paye_payable(): void
    {
        $this->employed();

        $row = $this->report()['rows'][0];

        $this->assertSame(
            round($row['tax_on_chargeable'] - $row['personal_relief'] - $row['insurance_relief'], 2),
            $row['paye_payable'],
        );
    }

    /** NSSF tiers are a domestic distinction; KRA asks for the contribution. */
    public function test_the_two_nssf_tiers_are_reported_as_one_contribution(): void
    {
        $this->employed();

        $this->assertSame(4320.0, $this->report()['rows'][0]['pension']);
    }

    // ── Nobody without a PIN goes in quietly ──────────────────────────────────

    public function test_an_employee_without_a_kra_pin_is_held_out_and_named(): void
    {
        $this->employed(['first_name' => 'Has']);
        $without = $this->employed(['first_name' => 'Hasnt', 'tax_id' => null]);

        $report = $this->report();

        $this->assertCount(1, $report['rows']);
        $this->assertCount(1, $report['missing']);
        $this->assertSame($without->id, $report['missing'][0]['employee_id']);
        $this->assertStringContainsString('KRA PIN', $report['missing'][0]['reason']);
    }

    /**
     * How much tax is sitting outside the return. Whoever is chasing the PIN
     * needs the figure, not just the name.
     */
    public function test_the_tax_left_out_of_the_return_is_stated(): void
    {
        $this->employed();
        $this->employed(['tax_id' => null]);

        $report = $this->report();

        $this->assertSame(48536.80, $report['excluded_paye']);
        $this->assertSame(48536.80, $report['totals']['paye_payable']);
    }

    // ── The file ──────────────────────────────────────────────────────────────

    public function test_the_totals_row_carries_what_is_remitted(): void
    {
        $this->employed(['first_name' => 'Aa']);
        $this->employed(['first_name' => 'Bb']);

        $csv   = app(P10Report::class)->csv($this->report());
        $lines = array_values(array_filter(explode("\r\n", $csv)));

        // Header, two employees, one control row.
        $this->assertCount(4, $lines);
        $this->assertStringContainsString('"TOTAL","2"', end($lines));
        // Two people at 48,536.80 is what the employer pays KRA this month.
        $this->assertStringContainsString('97073.60', end($lines));
    }

    public function test_a_comma_in_a_name_does_not_shift_the_columns(): void
    {
        $this->employed(['first_name' => 'Ann, Marie', 'last_name' => 'Otieno']);

        $csv = app(P10Report::class)->csv($this->report());

        $this->assertStringContainsString('"Ann, Marie Otieno"', $csv);
        $this->assertCount(3, array_values(array_filter(explode("\r\n", $csv))));
    }

    // ── The endpoint ──────────────────────────────────────────────────────────

    private function signIn(array $permissions): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Payroll', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    /** The deadline is why somebody opened the screen; it is not in the figures. */
    public function test_it_states_when_the_return_is_due(): void
    {
        $this->employed();
        $this->signIn([Permission::PAYROLL_VIEW]);

        $this->getJson("/api/v1/payroll/{$this->period->id}/p10", ['X-Organization' => 'alpha'])
            ->assertOk()
            // August's return is due by the ninth of September.
            ->assertJsonPath('due_on', '2026-09-09');
    }

    /** Filing from figures that can still move is how the two stop agreeing. */
    public function test_a_draft_period_cannot_produce_a_return(): void
    {
        $this->employed();
        $this->period->update(['status' => PayrollPeriod::STATUS_DRAFT]);
        $this->signIn([Permission::PAYROLL_VIEW]);

        $this->getJson("/api/v1/payroll/{$this->period->id}/p10.csv", ['X-Organization' => 'alpha'])
            ->assertStatus(422);
    }

    public function test_the_file_downloads_for_an_approved_period(): void
    {
        $this->employed();
        $this->signIn([Permission::PAYROLL_VIEW]);

        $this->getJson("/api/v1/payroll/{$this->period->id}/p10.csv", ['X-Organization' => 'alpha'])
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }
}
