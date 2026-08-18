<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\PaymentBatch;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Role;
use App\Models\User;
use App\Services\Payroll\PaymentFileBuilder;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The payroll as a file a bank will accept.
 *
 * The failure mode worth testing for is not a malformed file — a bank rejects
 * those loudly. It is a file that looks complete and is not: the bank pays what
 * it is given, reports success, and the person left out finds out on payday.
 */
class PaymentFileTest extends TestCase
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

    private function payable(array $attrs = [], float $net = 100000): Employee
    {
        $employee = Employee::create(array_merge([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Grace', 'last_name' => 'Njoroge',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'national_id' => (string) fake()->unique()->numberBetween(10000000, 99999999),
            'payment_method' => 'bank', 'bank_name' => 'Equity Bank',
            'bank_code' => '68', 'branch_code' => '068001',
            'bank_account_number' => '01234567890',
        ], $attrs));

        PayrollRun::create([
            'payroll_period_id' => $this->period->id,
            'employee_id'       => $employee->id,
            'basic_salary' => $net, 'gross_pay' => $net,
            'total_deductions' => 0, 'net_pay' => $net, 'status' => 'computed',
        ]);

        return $employee;
    }

    private function builder(): PaymentFileBuilder
    {
        return app(PaymentFileBuilder::class);
    }

    private function signIn(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Payroll', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    // ── Nobody is dropped silently ────────────────────────────────────────────

    /** The whole point: a missing account number is named, not omitted. */
    public function test_somebody_without_an_account_is_excluded_by_name(): void
    {
        $this->payable(['first_name' => 'Paid']);
        $missing = $this->payable(['first_name' => 'Unpaid', 'bank_account_number' => null]);

        $preview = $this->builder()->preview($this->period);

        $this->assertSame(1, $preview['count']);
        $this->assertCount(1, $preview['excluded']);
        $this->assertSame($missing->id, $preview['excluded'][0]['employee_id']);
        $this->assertStringContainsString('account number', $preview['excluded'][0]['reason']);
    }

    /** An account number with no bank will not route, and is caught here. */
    public function test_an_account_with_no_bank_is_excluded(): void
    {
        $this->payable(['bank_name' => null, 'bank_code' => null]);

        $preview = $this->builder()->preview($this->period);

        $this->assertSame(0, $preview['count']);
        $this->assertStringContainsString('will not route', $preview['excluded'][0]['reason']);
    }

    /** Being paid in cash is a fact about somebody, not an error. */
    public function test_cash_and_cheque_employees_are_excluded_with_that_reason(): void
    {
        $this->payable(['payment_method' => 'cash', 'bank_account_number' => null]);

        $preview = $this->builder()->preview($this->period);

        $this->assertSame(0, $preview['count']);
        $this->assertStringContainsString('Paid by cash', $preview['excluded'][0]['reason']);
    }

    /** A zero net pay is a real month, and a zero instruction breaks a file. */
    public function test_nothing_to_pay_is_excluded_rather_than_sent_as_zero(): void
    {
        $this->payable([], net: 0);

        $preview = $this->builder()->preview($this->period);

        $this->assertSame(0, $preview['count']);
        $this->assertStringContainsString('Nothing to pay', $preview['excluded'][0]['reason']);
    }

    // ── The file ──────────────────────────────────────────────────────────────

    public function test_the_totals_row_matches_what_is_in_the_file(): void
    {
        $this->payable(['first_name' => 'Aa'], net: 100000);
        $this->payable(['first_name' => 'Bb'], net: 50000);

        $preview = $this->builder()->preview($this->period);
        $csv     = $this->builder()->build($preview, 'standard', 'PAY-202608-01', '2026-08-28');

        $lines = array_values(array_filter(explode("\r\n", $csv)));

        // Header, two employees, one control row.
        $this->assertCount(4, $lines);
        $this->assertStringContainsString('"TOTAL","2","150000.00"', end($lines));
    }

    /** A name with a comma in it must not shift every column after it. */
    public function test_a_comma_in_a_name_does_not_break_the_columns(): void
    {
        $this->payable(['first_name' => 'Ann, Marie', 'last_name' => 'Otieno']);

        $preview = $this->builder()->preview($this->period);
        $csv     = $this->builder()->build($preview, 'simple', 'PAY-202608-01');

        $this->assertStringContainsString('"Ann, Marie Otieno"', $csv);
        // Header, one employee, one control row — not four columns become five.
        $this->assertCount(3, array_values(array_filter(explode("\r\n", $csv))));
    }

    /** The bank holds a name payroll does not always hold. */
    public function test_the_account_name_is_used_where_it_differs(): void
    {
        $this->payable(['bank_account_name' => 'G W NJOROGE']);

        $preview = $this->builder()->preview($this->period);

        $this->assertSame('G W NJOROGE', $preview['rows'][0]['account_name']);
    }

    // ── The endpoint ──────────────────────────────────────────────────────────

    public function test_a_draft_period_cannot_produce_a_payment_file(): void
    {
        $this->payable();
        $this->period->update(['status' => PayrollPeriod::STATUS_DRAFT]);
        $this->signIn([Permission::PAYROLL_PROCESS, Permission::PAYROLL_VIEW]);

        $this->postJson("/api/v1/payroll/{$this->period->id}/payment-file", [], ['X-Organization' => 'alpha'])
            ->assertStatus(422);

        $this->assertSame(0, PaymentBatch::count());
    }

    /** Generating it records it, because uploading twice pays everybody twice. */
    public function test_generating_records_a_batch_with_its_control_totals(): void
    {
        $this->payable(['first_name' => 'Aa'], net: 100000);
        $this->payable(['first_name' => 'Bb', 'bank_account_number' => null], net: 40000);
        $this->signIn([Permission::PAYROLL_PROCESS, Permission::PAYROLL_VIEW]);

        $this->postJson("/api/v1/payroll/{$this->period->id}/payment-file", [], ['X-Organization' => 'alpha'])
            ->assertOk();

        $batch = PaymentBatch::firstOrFail();

        $this->assertSame(1, $batch->record_count);
        $this->assertSame('100000.00', $batch->total_amount);
        // The gap between payroll and the batch has a written explanation.
        $this->assertSame(1, $batch->excluded_count);
        $this->assertStringContainsString('account number', $batch->excluded[0]['reason']);
    }

    /** A second file for the same month says which it is. */
    public function test_a_second_batch_is_numbered_rather_than_overwriting(): void
    {
        $this->payable();
        $this->signIn([Permission::PAYROLL_PROCESS, Permission::PAYROLL_VIEW]);

        $url = "/api/v1/payroll/{$this->period->id}/payment-file";
        $this->postJson($url, [], ['X-Organization' => 'alpha'])->assertOk();
        $this->postJson($url, [], ['X-Organization' => 'alpha'])->assertOk();

        $this->assertSame(
            ['PAY-202608-01', 'PAY-202608-02'],
            PaymentBatch::orderBy('id')->pluck('reference')->all(),
        );
    }

    /** Nothing payable produces no file and no batch, with the reason. */
    public function test_a_period_with_nobody_payable_generates_nothing(): void
    {
        $this->payable(['bank_account_number' => null]);
        $this->signIn([Permission::PAYROLL_PROCESS, Permission::PAYROLL_VIEW]);

        $this->postJson("/api/v1/payroll/{$this->period->id}/payment-file", [], ['X-Organization' => 'alpha'])
            ->assertStatus(422)
            ->assertJsonPath('excluded.0.reason', 'No bank account number on file');

        $this->assertSame(0, PaymentBatch::count());
    }

    public function test_generating_needs_the_permission(): void
    {
        $this->payable();
        $this->signIn([Permission::PAYROLL_VIEW]);

        $this->postJson("/api/v1/payroll/{$this->period->id}/payment-file", [], ['X-Organization' => 'alpha'])
            ->assertForbidden();
    }
}
