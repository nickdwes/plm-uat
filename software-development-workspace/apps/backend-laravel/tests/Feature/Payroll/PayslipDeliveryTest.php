<?php

namespace Tests\Feature\Payroll;

use App\Mail\PayslipMail;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\PayrollDocumentSetting;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\PayslipDelivery;
use App\Services\Payroll\PayslipDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Getting payslips to people, and knowing what happened.
 *
 * Mail is faked throughout. This is the one service in the system that puts
 * somebody's salary in their personal inbox, and a test that actually sent
 * would be a test that cannot be re-run.
 */
class PayslipDeliveryTest extends TestCase
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
            // Unique per row: national_id carries a unique index, and a fixed
            // fixture collides the moment a test needs two employees.
            'national_id'    => (string) fake()->unique()->numberBetween(10000000, 99999999),
            'personal_email' => 'grace' . fake()->unique()->numberBetween(1, 999999) . '@example.com',
        ], $attrs));
    }

    private function payrollRun(Employee $employee): PayrollRun
    {
        return PayrollRun::create([
            'payroll_period_id' => $this->period->id,
            'employee_id'       => $employee->id,
            'basic_salary' => 200000, 'gross_pay' => 200000,
            'paye_payable' => 48536.80, 'shif' => 5500,
            'nssf_tier1' => 480, 'nssf_tier2' => 3840, 'housing_levy' => 3000,
            'personal_relief' => 2400, 'total_deductions' => 61356.80,
            'net_pay' => 138643.20, 'status' => 'approved',
        ]);
    }

    private function service(): PayslipDeliveryService
    {
        return app(PayslipDeliveryService::class);
    }

    // ── Sending ───────────────────────────────────────────────────────────────

    public function test_a_payslip_is_emailed_with_the_encrypted_attachment(): void
    {
        $grace = $this->employee(['personal_email' => 'grace@example.com']);
        $this->payrollRun($grace);

        $result = $this->service()->send($this->period->fresh(), [$grace->id]);

        $this->assertSame(1, $result['sent']);
        Mail::assertSent(PayslipMail::class, fn ($mail) => $mail->hasTo('grace@example.com'));
    }

    /** A payslip outlives the job, and the work mailbox does not. */
    public function test_the_personal_address_is_preferred(): void
    {
        $grace = $this->employee([
            'personal_email' => 'grace@personal.com',
            'work_email'     => 'grace@company.com',
        ]);
        $this->payrollRun($grace);

        $this->service()->send($this->period->fresh(), [$grace->id]);

        Mail::assertSent(PayslipMail::class, fn ($mail) => $mail->hasTo('grace@personal.com'));
    }

    public function test_the_work_address_is_used_when_there_is_no_personal_one(): void
    {
        $grace = $this->employee(['personal_email' => null, 'work_email' => 'grace@company.com']);
        $this->payrollRun($grace);

        $this->service()->send($this->period->fresh(), [$grace->id]);

        Mail::assertSent(PayslipMail::class, fn ($mail) => $mail->hasTo('grace@company.com'));
    }

    // ── Held back ─────────────────────────────────────────────────────────────

    /**
     * The rule the whole thing rests on: no password, no email. Not an open
     * attachment, not a skipped record — held, with the reason.
     */
    public function test_somebody_with_no_password_source_is_held_not_sent(): void
    {
        $settings = PayrollDocumentSetting::forOrganization($this->org->id);
        $settings->update(['password_fallback_source' => null]);

        $nobody = $this->employee(['national_id' => null]);
        $this->payrollRun($nobody);

        $result = $this->service()->send($this->period->fresh(), [$nobody->id]);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['held']);
        Mail::assertNothingSent();

        $record = PayslipDelivery::where('employee_id', $nobody->id)->first();
        $this->assertSame(PayslipDelivery::STATUS_HELD, $record->status);
        $this->assertStringContainsString('not on file', $record->detail);
    }

    public function test_somebody_with_no_address_is_held(): void
    {
        $nobody = $this->employee(['personal_email' => null, 'work_email' => null]);
        $this->payrollRun($nobody);

        $result = $this->service()->send($this->period->fresh(), [$nobody->id]);

        $this->assertSame(1, $result['held']);
        Mail::assertNothingSent();
        $this->assertSame(
            'No email address on file',
            PayslipDelivery::where('employee_id', $nobody->id)->first()->detail,
        );
    }

    /** One bad address must not strand everybody behind it. */
    public function test_one_held_payslip_does_not_stop_the_rest(): void
    {
        $ok      = $this->employee(['first_name' => 'Ada']);
        $noEmail = $this->employee(['first_name' => 'Ben', 'personal_email' => null, 'work_email' => null]);
        $this->payrollRun($ok);
        $this->payrollRun($noEmail);

        $result = $this->service()->send($this->period->fresh(), [$ok->id, $noEmail->id]);

        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['held']);
        Mail::assertSent(PayslipMail::class, 1);
    }

    // ── The record ────────────────────────────────────────────────────────────

    /** "Did she get her payslip?" is asked every month. */
    public function test_every_outcome_is_recorded(): void
    {
        $grace = $this->employee(['personal_email' => 'grace@example.com']);
        $this->payrollRun($grace);

        $this->service()->send($this->period->fresh(), [$grace->id]);

        $record = PayslipDelivery::where('employee_id', $grace->id)->first();
        $this->assertSame(PayslipDelivery::STATUS_SENT, $record->status);
        $this->assertSame('grace@example.com', $record->recipient);
        $this->assertNotNull($record->sent_at);
    }

    /** Re-sending updates the attempt rather than stacking rows. */
    public function test_sending_twice_leaves_one_record(): void
    {
        $grace = $this->employee();
        $this->payrollRun($grace);

        $this->service()->send($this->period->fresh(), [$grace->id]);
        $this->service()->send($this->period->fresh(), [$grace->id]);

        $this->assertSame(1, PayslipDelivery::where('employee_id', $grace->id)->count());
        Mail::assertSent(PayslipMail::class, 2);
    }

    // ── Who can be sent one ───────────────────────────────────────────────────

    public function test_recipients_name_who_cannot_be_sent_one_and_why(): void
    {
        $settings = PayrollDocumentSetting::forOrganization($this->org->id);
        $settings->update(['password_fallback_source' => null]);

        $fine    = $this->employee(['first_name' => 'Ada']);
        $noMail  = $this->employee(['first_name' => 'Ben', 'personal_email' => null, 'work_email' => null]);
        $noId    = $this->employee(['first_name' => 'Cara', 'national_id' => null]);
        foreach ([$fine, $noMail, $noId] as $e) { $this->payrollRun($e); }

        $rows = collect($this->service()->recipients($this->period->fresh()))->keyBy('name');

        $this->assertTrue($rows['Ada Njoroge']['sendable']);
        $this->assertFalse($rows['Ben Njoroge']['sendable']);
        $this->assertSame('No email address on file', $rows['Ben Njoroge']['blocked_reason']);
        $this->assertFalse($rows['Cara Njoroge']['sendable']);
        $this->assertStringContainsString('password', $rows['Cara Njoroge']['blocked_reason']);
    }
}
