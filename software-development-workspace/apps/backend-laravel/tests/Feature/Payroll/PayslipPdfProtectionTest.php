<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\PayrollDocumentSetting;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Services\Payroll\PayslipPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A payslip cannot leave this system unlocked.
 *
 * It carries a salary, a tax number and bank details, it is emailed to a
 * personal address and forwarded from there. So protection is not a flag a
 * caller passes or an option a send screen offers — these tests exist to prove
 * there is no route to the bytes without it, including the one somebody adds
 * next year without reading this file.
 */
class PayslipPdfProtectionTest extends TestCase
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
            'name'            => 'August 2026',
            'start_date'      => '2026-08-01',
            'end_date'        => '2026-08-31',
            'payment_date'    => '2026-08-28',
            'status'          => PayrollPeriod::STATUS_APPROVED,
        ]);
    }

    private function employee(array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Grace', 'last_name' => 'Njoroge',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'national_id' => '12345678',
        ], $attrs));
    }

    private function payrollRun(Employee $employee): PayrollRun
    {
        return PayrollRun::create([
            'payroll_period_id' => $this->period->id,
            'employee_id'       => $employee->id,
            'basic_salary'      => 200000,
            'gross_pay'         => 200000,
            'paye_payable'      => 48536.80,
            'shif'              => 5500,
            'nssf_tier1'        => 480,
            'nssf_tier2'        => 3840,
            'housing_levy'      => 3000,
            'personal_relief'   => 2400,
            'total_deductions'  => 61356.80,
            'net_pay'           => 138643.20,
            'status'            => 'computed',
        ]);
    }

    private function pdf(): PayslipPdf
    {
        return app(PayslipPdf::class);
    }

    // ── The document ──────────────────────────────────────────────────────────

    public function test_every_payslip_is_encrypted(): void
    {
        $run = $this->payrollRun($this->employee())->load(['employee', 'period.organization']);

        $document = $this->pdf()->render($run);

        $this->assertStringContainsString('%PDF-', substr($document['content'], 0, 8));
        // The encryption dictionary is what makes it a locked document.
        $this->assertStringContainsString('/Encrypt', $document['content']);
    }

    /**
     * The figures must be inside the encrypted stream, not sitting in plain
     * text where `strings` would find them.
     */
    public function test_the_amounts_are_not_readable_without_the_password(): void
    {
        $run = $this->payrollRun($this->employee())->load(['employee', 'period.organization']);

        $content = $this->pdf()->render($run)['content'];

        $this->assertStringNotContainsString('138,643.20', $content);
        $this->assertStringNotContainsString('Grace Njoroge', $content);
    }

    // ── The password ──────────────────────────────────────────────────────────

    public function test_the_password_is_the_national_id_by_default(): void
    {
        $employee = $this->employee(['national_id' => '  12 345-678 ']);
        $settings = PayrollDocumentSetting::forOrganization($this->org->id);

        // Spaces and dashes are typed inconsistently, so they are stripped.
        $this->assertSame('12345678', $this->pdf()->passwordFor($employee, $settings));
    }

    public function test_a_prefix_suffix_and_case_are_applied(): void
    {
        $employee = $this->employee(['national_id' => 'ab1234']);
        $settings = PayrollDocumentSetting::forOrganization($this->org->id);
        $settings->update(['password_prefix' => 'KNS-', 'password_suffix' => '!', 'password_case' => 'upper']);

        $this->assertSame('KNS-AB1234!', $this->pdf()->passwordFor($employee->fresh(), $settings->fresh()));
    }

    /** Records are incomplete in practice, and a payslip still has to arrive. */
    public function test_it_falls_back_when_the_first_source_is_missing(): void
    {
        $employee = $this->employee(['national_id' => null, 'employee_number' => 'EMP-0042']);
        $settings = PayrollDocumentSetting::forOrganization($this->org->id);

        $this->assertSame('EMP0042', $this->pdf()->passwordFor($employee, $settings));
    }

    /**
     * The whole point. With nothing to build a password from it refuses rather
     * than producing an open document — a payslip that does not arrive is
     * fixed in a minute, one that arrives unlocked cannot be taken back.
     */
    public function test_it_refuses_rather_than_producing_an_unprotected_payslip(): void
    {
        $employee = $this->employee(['national_id' => null]);
        $settings = PayrollDocumentSetting::forOrganization($this->org->id);
        $settings->update(['password_fallback_source' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Grace Njoroge/');

        $this->pdf()->passwordFor($employee, $settings->fresh());
    }

    /** Checkable before a send, so a run does not fail halfway through. */
    public function test_whether_somebody_can_be_sent_one_is_answerable_in_advance(): void
    {
        $settings = PayrollDocumentSetting::forOrganization($this->org->id);
        $settings->update(['password_fallback_source' => null]);

        $withId    = $this->employee(['national_id' => '11112222']);
        $withoutId = $this->employee(['national_id' => null]);

        $this->assertTrue($this->pdf()->canDeriveFor($withId, $settings->fresh()));
        $this->assertFalse($this->pdf()->canDeriveFor($withoutId, $settings->fresh()));
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    /**
     * An organisation created after this table existed must not be the one that
     * emits an unlocked payslip because nobody opened a settings page.
     */
    public function test_a_new_organisation_is_protected_without_anybody_configuring_it(): void
    {
        $late = Organization::create(['name' => 'Beta', 'slug' => 'beta']);

        $settings = PayrollDocumentSetting::forOrganization($late->id);

        $this->assertSame('national_id', $settings->password_source);
        $this->assertNotEmpty($settings->owner_password);
    }

    /** The hint is what a covering email may say; the password never is. */
    public function test_the_hint_describes_the_password_without_giving_it(): void
    {
        $settings = PayrollDocumentSetting::forOrganization($this->org->id);
        $settings->update(['password_prefix' => 'KNS-', 'password_case' => 'upper']);

        $hint = $settings->fresh()->describe();

        $this->assertStringContainsString('National ID', $hint);
        $this->assertStringContainsString('KNS-', $hint);
        $this->assertStringNotContainsString('12345678', $hint);
    }

    /** It is the one value here that is a secret. */
    public function test_the_owner_password_is_encrypted_and_never_serialised(): void
    {
        $settings = PayrollDocumentSetting::forOrganization($this->org->id);

        $stored = \DB::table('payroll_document_settings')
            ->where('id', $settings->id)->value('owner_password');

        $this->assertNotSame($settings->owner_password, $stored);
        $this->assertArrayNotHasKey('owner_password', $settings->toArray());
    }
}
