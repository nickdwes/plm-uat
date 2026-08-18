<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\PayrollEarningCode;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\PayrollRunItem;
use App\Models\Role;
use App\Support\Authorization\Permission;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Services\Payroll\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ad-hoc lines on a run: overtime, a bonus, a per diem, an advance recovery.
 *
 * The engine had read this table since it was created and nothing could write
 * to it, so every run was a straight function of standing salary. These tests
 * cover the two things that go wrong once it can be written: the net pay not
 * following the line, and a non-taxable payment being taxed.
 */
class RunItemTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private PayrollPeriod $period;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $this->period = PayrollPeriod::create([
            'organization_id' => $this->org->id,
            'name'            => 'August 2026',
            'start_date'      => '2026-08-01',
            'end_date'        => '2026-08-31',
            'payment_date'    => '2026-08-28',
            'status'          => PayrollPeriod::STATUS_DRAFT,
        ]);

        $this->employee = Employee::create([
            'employee_number'         => 'EMP-0001',
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Grace', 'last_name' => 'Njoroge',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'status' => 'active', 'national_id' => '12345678',
            'basic_salary' => 100000,
        ]);
    }

    private function service(): PayrollService
    {
        return app(PayrollService::class);
    }

    private function computedRun(): PayrollRun
    {
        $this->service()->processPeriod($this->period->id);

        return PayrollRun::where('payroll_period_id', $this->period->id)
            ->where('employee_id', $this->employee->id)
            ->firstOrFail();
    }

    private function addItem(PayrollRun $run, array $attrs): PayrollRunItem
    {
        $item = PayrollRunItem::create(array_merge([
            'payroll_run_id' => $run->id,
            'type'           => PayrollRunItem::TYPE_EARNING,
            'label'          => 'Overtime',
            'amount'         => 10000,
            'is_taxable'     => true,
        ], $attrs));

        $this->service()->recomputeRun($this->period->id, $this->employee->id);

        return $item;
    }

    // ── The line has to move the money ────────────────────────────────────────

    /** The whole reason the table existed and could not be written to. */
    public function test_a_taxable_bonus_raises_gross_and_paye(): void
    {
        $before = $this->computedRun();
        $grossBefore = (float) $before->gross_pay;
        $payeBefore  = (float) $before->paye_payable;

        $this->addItem($before, ['label' => 'Bonus', 'amount' => 50000]);

        $after = $before->fresh();

        $this->assertSame(round($grossBefore + 50000, 2), round((float) $after->gross_pay, 2));
        $this->assertGreaterThan($payeBefore, (float) $after->paye_payable);
    }

    public function test_a_deduction_lowers_net_pay_without_touching_gross(): void
    {
        $run = $this->computedRun();
        $grossBefore = (float) $run->gross_pay;
        $netBefore   = (float) $run->net_pay;

        $this->addItem($run, [
            'type'   => PayrollRunItem::TYPE_DEDUCTION,
            'label'  => 'Salary advance recovery',
            'amount' => 15000,
        ]);

        $after = $run->fresh();

        // An advance recovery is repayment of money already paid; it is not a
        // reduction in what was earned, so it must not reduce the tax base.
        $this->assertSame(round($grossBefore, 2), round((float) $after->gross_pay, 2));
        $this->assertSame(round($netBefore - 15000, 2), round((float) $after->net_pay, 2));
    }

    /** Removing the line must put the run back, not leave the money applied. */
    public function test_deleting_a_line_reverses_it(): void
    {
        $run = $this->computedRun();
        $netBefore = (float) $run->net_pay;

        $item = $this->addItem($run, ['label' => 'Bonus', 'amount' => 50000]);
        $this->assertNotSame($netBefore, (float) $run->fresh()->net_pay);

        $item->delete();
        $this->service()->recomputeRun($this->period->id, $this->employee->id);

        $this->assertSame(round($netBefore, 2), round((float) $run->fresh()->net_pay, 2));
    }

    // ── Non-taxable pay ───────────────────────────────────────────────────────

    /**
     * The defect this change existed to avoid. A per diem is paid in full and
     * not taxed; putting it through gross charges PAYE on money KRA never
     * asked for.
     */
    public function test_a_per_diem_is_paid_in_full_and_not_taxed(): void
    {
        $run = $this->computedRun();
        $grossBefore = (float) $run->gross_pay;
        $payeBefore  = (float) $run->paye_payable;
        $netBefore   = (float) $run->net_pay;

        $this->addItem($run, ['label' => 'Per diem', 'amount' => 20000, 'is_taxable' => false]);

        $after = $run->fresh();

        $this->assertSame(round($grossBefore, 2), round((float) $after->gross_pay, 2));
        $this->assertSame(round($payeBefore, 2), round((float) $after->paye_payable, 2));
        $this->assertSame(round($netBefore + 20000, 2), round((float) $after->net_pay, 2));
        $this->assertSame(20000.0, round((float) $after->non_taxable_earnings, 2));
    }

    /**
     * gross_pay is the statutory base and what column A+B+C of the P9 reports.
     * A per diem inside it would put a figure on a KRA return that the PAYE
     * printed beside it disagrees with.
     */
    public function test_non_taxable_pay_stays_out_of_the_p9(): void
    {
        $run = $this->computedRun();
        $this->addItem($run, ['label' => 'Per diem', 'amount' => 20000, 'is_taxable' => false]);

        // The P9 only counts approved payroll, so the period has to be released
        // before there is anything to compare against.
        $this->period->update(['status' => PayrollPeriod::STATUS_APPROVED]);

        $months = $this->service()->getP9Months($this->org->id, 2026, $this->employee->id);

        $this->assertSame(round((float) $run->fresh()->gross_pay, 2), round($months[0]['gross_pay'], 2));
    }

    // ── How the treatment is decided ──────────────────────────────────────────

    /** Whether a payment is taxed is a matter of law, not a form field. */
    public function test_the_tax_treatment_comes_from_the_code(): void
    {
        $run = $this->computedRun();

        $perDiem = PayrollEarningCode::create([
            'organization_id' => $this->org->id,
            'code' => 'PERDIEM', 'name' => 'Per Diem', 'is_taxable' => false, 'is_active' => true,
        ]);

        $this->admin();

        $this->postJson("/api/v1/payroll/runs/{$run->id}/items", [
                'type' => 'earning', 'label' => 'Per diem', 'amount' => 20000,
                'payroll_earning_code_id' => $perDiem->id,
                // Asserted by the client and ignored: the code decides.
                'is_taxable' => true,
            ], ['X-Organization' => $this->org->slug])
            ->assertCreated();

        $this->assertFalse((bool) PayrollRunItem::where('payroll_run_id', $run->id)->first()->is_taxable);
    }

    /**
     * An uncoded one-off is taxed. Treating an unclassified payment as
     * tax-free is the error that costs both sides at the next KRA audit.
     */
    public function test_an_uncoded_line_is_taxed(): void
    {
        $run = $this->computedRun();

        $this->admin();

        $this->postJson("/api/v1/payroll/runs/{$run->id}/items", [
                'type' => 'earning', 'label' => 'Ex gratia', 'amount' => 5000,
            ], ['X-Organization' => $this->org->slug])
            ->assertCreated();

        $this->assertTrue((bool) PayrollRunItem::where('payroll_run_id', $run->id)->first()->is_taxable);
    }

    /** A "deduction" of minus five thousand would quietly pay somebody. */
    public function test_a_negative_amount_is_refused(): void
    {
        $run = $this->computedRun();

        $this->admin();

        $this->postJson("/api/v1/payroll/runs/{$run->id}/items", [
                'type' => 'deduction', 'label' => 'Refund', 'amount' => -5000,
            ], ['X-Organization' => $this->org->slug])
            ->assertStatus(422);
    }

    // ── When the run closes ───────────────────────────────────────────────────

    /**
     * Once approved, payslips may be in inboxes and the P9 is being built from
     * these figures. A run changed underneath one leaves somebody holding a
     * document this system no longer agrees with.
     */
    public function test_lines_cannot_be_added_once_the_period_is_approved(): void
    {
        $run = $this->computedRun();
        $this->period->update(['status' => PayrollPeriod::STATUS_APPROVED]);

        $this->admin();

        $this->postJson("/api/v1/payroll/runs/{$run->id}/items", [
                'type' => 'earning', 'label' => 'Late overtime', 'amount' => 5000,
            ], ['X-Organization' => $this->org->slug])
            ->assertStatus(422);

        $this->assertSame(0, PayrollRunItem::where('payroll_run_id', $run->id)->count());
    }

    /**
     * A run is not addressable from a tenant it does not belong to, even by
     * somebody who legitimately holds payroll rights in both.
     *
     * Roled in both organisations on purpose: with rights in only one the
     * request is refused at the permission check and proves nothing about
     * whether the run itself is scoped.
     */
    public function test_a_run_in_another_organisation_is_not_reachable(): void
    {
        $run   = $this->computedRun();
        $other = Organization::create(['name' => 'Beta', 'slug' => 'beta']);

        $user = $this->admin();
        $user->roles()->attach($user->roles->first()->id, ['organization_id' => $other->id]);
        $user->forgetCachedRoles();

        $this->getJson("/api/v1/payroll/runs/{$run->id}/items", ['X-Organization' => $other->slug])
            ->assertStatus(404);
    }

    /** A signed-in payroll officer, roled the way the app actually roles one. */
    private function admin(): User
    {
        $user = User::factory()->create();

        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Payroll', 'scope' => 'organization',
            'permissions' => [Permission::PAYROLL_VIEW, Permission::PAYROLL_PROCESS],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        Sanctum::actingAs($user);

        return $user->fresh();
    }
}
