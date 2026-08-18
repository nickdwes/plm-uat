<?php

namespace Tests\Feature\Payroll;

use App\Mail\P9Mail;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\PayrollDocumentSetting;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\PayslipDelivery;
use App\Services\Payroll\P9DeliveryService;
use App\Services\Payroll\P9Pdf;
use App\Services\Payroll\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A P9A carries a year of somebody's pay and their KRA PIN.
 *
 * It is the same exposure as a payslip and then some, so these tests hold it to
 * the same rule: no route to the bytes without a password, held is not failed,
 * and one bad address does not stop the year.
 */
class P9DeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private PayrollPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

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
            'national_id' => (string) fake()->unique()->numberBetween(10000000, 99999999),
            'personal_email' => fake()->unique()->safeEmail(),
        ], $attrs));
    }

    private function paid(Employee $employee): PayrollRun
    {
        return PayrollRun::create([
            'payroll_period_id' => $this->period->id,
            'employee_id'       => $employee->id,
            'basic_salary'      => 200000, 'gross_pay' => 200000,
            'taxable_income'    => 187180,
            'paye'              => 50936.80, 'paye_payable' => 48536.80,
            'shif'              => 5500, 'nssf_tier1' => 480, 'nssf_tier2' => 3840,
            'housing_levy'      => 3000, 'personal_relief' => 2400,
            'total_deductions'  => 61356.80, 'net_pay' => 138643.20,
            'status'            => 'computed',
        ]);
    }

    private function delivery(): P9DeliveryService
    {
        return app(P9DeliveryService::class);
    }

    // ── The document ──────────────────────────────────────────────────────────

    public function test_the_p9_is_encrypted_like_everything_else(): void
    {
        $employee = $this->employee(['national_id' => '12345678']);
        $this->paid($employee);

        $months   = app(PayrollService::class)->getP9Months($this->org->id, 2026, $employee->id);
        $document = app(P9Pdf::class)->render($employee, 2026, $months, $this->org->id, 'Alpha');

        $this->assertStringContainsString('%PDF-', substr($document['content'], 0, 8));
        $this->assertStringContainsString('/Encrypt', $document['content']);
        // A year of pay must not be sitting in plain text in the file.
        $this->assertStringNotContainsString('187,180.00', $document['content']);
        $this->assertStringNotContainsString('Grace Njoroge', $document['content']);
    }

    /**
     * The same password the employee already uses on their payslips. A second
     * scheme for the same person is a support call every June.
     */
    public function test_it_opens_with_the_same_password_as_the_payslip(): void
    {
        $employee = $this->employee(['national_id' => '12345678']);
        $settings = PayrollDocumentSetting::forOrganization($this->org->id);

        $this->assertSame(
            app(\App\Services\Payroll\PayslipPdf::class)->passwordFor($employee, $settings),
            app(\App\Services\Payroll\ProtectedPdf::class)->passwordFor($employee, $settings),
        );
    }

    // ── Who can be sent one ───────────────────────────────────────────────────

    /** Only people with approved payroll behind them; an empty P9 helps nobody. */
    public function test_only_employees_paid_that_year_are_listed(): void
    {
        $paid   = $this->employee(['first_name' => 'Grace']);
        $unpaid = $this->employee(['first_name' => 'Peter']);
        $this->paid($paid);

        $recipients = $this->delivery()->recipients($this->org->id, 2026);

        $this->assertCount(1, $recipients);
        $this->assertSame($paid->id, $recipients[0]['employee_id']);
        $this->assertTrue($recipients[0]['sendable']);
    }

    public function test_the_two_ways_of_being_unsendable_are_told_apart(): void
    {
        $settings = PayrollDocumentSetting::forOrganization($this->org->id);
        $settings->update(['password_fallback_source' => null]);

        $noAddress  = $this->employee(['first_name' => 'Aa', 'personal_email' => null, 'work_email' => null]);
        $noPassword = $this->employee(['first_name' => 'Bb', 'national_id' => null]);
        $this->paid($noAddress);
        $this->paid($noPassword);

        $by = collect($this->delivery()->recipients($this->org->id, 2026))->keyBy('employee_id');

        // Different problems with different fixes: one is an email to chase,
        // the other a record to complete.
        $this->assertStringContainsString('email address', $by[$noAddress->id]['blocked_reason']);
        $this->assertStringContainsString('password', $by[$noPassword->id]['blocked_reason']);
    }

    // ── Sending ───────────────────────────────────────────────────────────────

    public function test_it_sends_and_records_who_got_one(): void
    {
        $employee = $this->employee();
        $this->paid($employee);

        $result = $this->delivery()->send($this->org->id, 2026, [$employee->id]);

        $this->assertSame(1, $result['sent']);
        Mail::assertSent(P9Mail::class, 1);

        $this->assertDatabaseHas('payroll_payslip_deliveries', [
            'document_type' => PayslipDelivery::TYPE_P9,
            'tax_year'      => 2026,
            'employee_id'   => $employee->id,
            'status'        => PayslipDelivery::STATUS_SENT,
        ]);
    }

    /**
     * Nothing to build a password from means held, not sent and not failed:
     * no email is produced, and retrying will not change that until somebody
     * completes the record.
     */
    public function test_it_holds_rather_than_emailing_an_unlocked_p9(): void
    {
        $settings = PayrollDocumentSetting::forOrganization($this->org->id);
        $settings->update(['password_fallback_source' => null]);

        $employee = $this->employee(['national_id' => null]);
        $this->paid($employee);

        $result = $this->delivery()->send($this->org->id, 2026, [$employee->id]);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['held']);
        Mail::assertNothingSent();
    }

    /** Fifty P9As and the fortieth record is incomplete. The other forty-nine go. */
    public function test_one_bad_record_does_not_stop_the_year(): void
    {
        $settings = PayrollDocumentSetting::forOrganization($this->org->id);
        $settings->update(['password_fallback_source' => null]);

        $good    = $this->employee();
        $missing = $this->employee(['national_id' => null]);
        $this->paid($good);
        $this->paid($missing);

        $result = $this->delivery()->send($this->org->id, 2026, [$good->id, $missing->id]);

        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['held']);
        Mail::assertSent(P9Mail::class, 1);
    }

    /** Reissuing after a correction overwrites the record rather than doubling it. */
    public function test_sending_twice_leaves_one_record(): void
    {
        $employee = $this->employee();
        $this->paid($employee);

        $this->delivery()->send($this->org->id, 2026, [$employee->id]);
        $this->delivery()->send($this->org->id, 2026, [$employee->id]);

        $this->assertSame(1, PayslipDelivery::where('document_type', PayslipDelivery::TYPE_P9)
            ->where('employee_id', $employee->id)
            ->where('channel', PayslipDelivery::CHANNEL_EMAIL)
            ->count());
    }

    /** A P9 for 2026 and one for 2025 are two documents, not one sent twice. */
    public function test_each_year_is_recorded_separately(): void
    {
        $employee = $this->employee();
        $this->paid($employee);

        $earlier = PayrollPeriod::create([
            'organization_id' => $this->org->id, 'name' => 'August 2025',
            'start_date' => '2025-08-01', 'end_date' => '2025-08-31',
            'payment_date' => '2025-08-28', 'status' => PayrollPeriod::STATUS_APPROVED,
        ]);
        PayrollRun::create([
            'payroll_period_id' => $earlier->id, 'employee_id' => $employee->id,
            'basic_salary' => 180000, 'gross_pay' => 180000, 'net_pay' => 130000,
            'personal_relief' => 2400, 'status' => 'computed',
        ]);

        $this->delivery()->send($this->org->id, 2026, [$employee->id]);
        $this->delivery()->send($this->org->id, 2025, [$employee->id]);

        $this->assertSame(2, PayslipDelivery::where('document_type', PayslipDelivery::TYPE_P9)
            ->where('employee_id', $employee->id)
            ->where('status', PayslipDelivery::STATUS_SENT)
            ->count());
    }

    /**
     * A year the employee was not paid in is held, not sent. An empty P9A reads
     * as "you earned nothing", which is worse than none arriving at all.
     */
    public function test_it_will_not_send_a_p9_for_a_year_with_no_payroll(): void
    {
        $employee = $this->employee();
        $this->paid($employee);

        $result = $this->delivery()->send($this->org->id, 2024, [$employee->id]);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['held']);
        Mail::assertNothingSent();
    }

    /** The covering email says what opens the file, never the value. */
    public function test_the_email_hints_at_the_password_without_carrying_it(): void
    {
        $employee = $this->employee(['national_id' => '12345678']);
        $this->paid($employee);

        $this->delivery()->send($this->org->id, 2026, [$employee->id]);

        Mail::assertSent(P9Mail::class, function (P9Mail $mail) {
            $html = $mail->render();

            return str_contains($html, 'National ID') && ! str_contains($html, '12345678');
        });
    }

    /** A P9 outlives the job, and the work mailbox does not. */
    public function test_the_personal_address_is_preferred(): void
    {
        $employee = $this->employee([
            'personal_email' => 'grace@example.com',
            'work_email'     => 'grace@alpha.co.ke',
        ]);
        $this->paid($employee);

        $this->delivery()->send($this->org->id, 2026, [$employee->id]);

        $this->assertDatabaseHas('payroll_payslip_deliveries', [
            'employee_id' => $employee->id,
            'recipient'   => 'grace@example.com',
        ]);
    }
}
