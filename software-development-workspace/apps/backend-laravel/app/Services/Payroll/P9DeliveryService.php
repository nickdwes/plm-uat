<?php

namespace App\Services\Payroll;

use App\Mail\P9Mail;
use App\Models\Employee;
use App\Models\PayrollDocumentSetting;
use App\Models\PayslipDelivery;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Getting P9As to people.
 *
 * The same shape as PayslipDeliveryService and deliberately not folded into it:
 * they agree on the rules — nothing unlocked leaves, held is not failed, one
 * bad address does not stop the run — and differ on what a document is about,
 * a year rather than a period. The rules live in ProtectedPdf and in the
 * delivery table, which is where sharing them actually holds; a common base
 * class here would share only the loop.
 */
class P9DeliveryService
{
    public function __construct(
        private readonly P9Pdf $pdf,
        private readonly PayrollService $payroll,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Who has a P9 for this year, and where it would go.
     *
     * Only employees with approved payroll in the year: a P9 for somebody who
     * was not paid is an empty form, and sending one invites a question nobody
     * can answer.
     */
    public function recipients(int $organizationId, int $year): array
    {
        $settings = PayrollDocumentSetting::forOrganization($organizationId);

        return collect($this->payroll->getP9Report($organizationId, $year))
            ->map(function (array $row) use ($settings) {
                $employee = Employee::withoutGlobalScope('organization')
                    ->with('department')
                    ->find($row['employee']['id'] ?? null);

                if (! $employee) {
                    return null;
                }

                $address = $this->addressFor($employee);
                $canLock = $this->pdf->canDeriveFor($employee, $settings);

                return [
                    'employee_id'     => $employee->id,
                    'name'            => $employee->full_name,
                    'employee_number' => $employee->employee_number,
                    'department'      => $employee->department?->name,
                    'email'           => $address,
                    // What the payslip flow calls net pay. Here it is the
                    // figure the year is judged on: what was actually paid over.
                    'net_pay'         => (float) ($row['paye_payable'] ?? 0),
                    'sendable'        => $address !== null && $canLock,
                    'blocked_reason'  => match (true) {
                        $address === null => 'No email address on file',
                        ! $canLock        => 'Nothing to build a document password from',
                        default           => null,
                    },
                ];
            })
            ->filter()
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * @param  array $options subject, message, portal
     * @return array{sent: int, held: int, failed: int, results: array}
     */
    public function send(int $organizationId, int $year, array $employeeIds, array $options = [], ?int $actorId = null): array
    {
        $settings = PayrollDocumentSetting::forOrganization($organizationId);
        $org      = \App\Models\Organization::find($organizationId)?->name ?? '';

        $employees = Employee::withoutGlobalScope('organization')
            ->whereIn('id', $employeeIds)
            ->get();

        $results = [];

        foreach ($employees as $employee) {
            $results[] = $this->deliverOne($employee, $organizationId, $year, $settings, $org, $options, $actorId);

            if (($options['portal'] ?? false) && $employee->user_id) {
                $this->notifyInPortal($employee, $organizationId, $year, $actorId);
            }
        }

        return [
            'sent'    => collect($results)->where('status', PayslipDelivery::STATUS_SENT)->count(),
            'held'    => collect($results)->where('status', PayslipDelivery::STATUS_HELD)->count(),
            'failed'  => collect($results)->where('status', PayslipDelivery::STATUS_FAILED)->count(),
            'results' => $results,
        ];
    }

    private function deliverOne(
        Employee $employee,
        int $organizationId,
        int $year,
        PayrollDocumentSetting $settings,
        string $org,
        array $options,
        ?int $actorId,
    ): array {
        $address = $this->addressFor($employee);

        if ($address === null) {
            return $this->record($employee, $year, PayslipDelivery::STATUS_HELD, null,
                'No email address on file', $actorId, PayslipDelivery::CHANNEL_EMAIL);
        }

        $months = $this->payroll->getP9Months($organizationId, $year, $employee->id);

        /*
         * A year with no approved payroll produces a form with nothing on it.
         * The recipients list already excludes these people, but send() takes
         * whatever ids it is given — and an empty P9A in somebody's inbox reads
         * as "you earned nothing", which is worse than no P9A at all.
         */
        if ($months === []) {
            return $this->record($employee, $year, PayslipDelivery::STATUS_HELD, $address,
                "No approved payroll for {$year}, so there is nothing to certify",
                $actorId, PayslipDelivery::CHANNEL_EMAIL);
        }

        try {
            $document = $this->pdf->render($employee, $year, $months, $organizationId, $org);
        } catch (Throwable $e) {
            // Held: retrying changes nothing until somebody completes a record.
            return $this->record($employee, $year, PayslipDelivery::STATUS_HELD, $address,
                substr($e->getMessage(), 0, 480), $actorId, PayslipDelivery::CHANNEL_EMAIL);
        }

        try {
            Mail::to($address)->send(new P9Mail(
                employeeName:     $employee->full_name,
                year:             $year,
                organisationName: $org,
                passwordHint:     $document['password_hint'],
                pdf:              $document['content'],
                filename:         $document['filename'],
                subjectLine:      $options['subject'] ?? null,
                bodyText:         $options['message'] ?? null,
            ));
        } catch (Throwable $e) {
            return $this->record($employee, $year, PayslipDelivery::STATUS_FAILED, $address,
                substr($e->getMessage(), 0, 480), $actorId, PayslipDelivery::CHANNEL_EMAIL);
        }

        return $this->record($employee, $year, PayslipDelivery::STATUS_SENT, $address,
            null, $actorId, PayslipDelivery::CHANNEL_EMAIL);
    }

    private function notifyInPortal(Employee $employee, int $organizationId, int $year, ?int $actorId): void
    {
        try {
            $this->notifications->create(
                organizationId: (string) $organizationId,
                userId:         $employee->user_id,
                type:           'payslip',
                title:          "Your P9A for {$year} is ready",
                message:        'Open it from Payroll — My Pay History.',
                data:           ['tax_year' => $year],
            );

            $status = PayslipDelivery::STATUS_SENT;
            $detail = null;
        } catch (Throwable $e) {
            $status = PayslipDelivery::STATUS_FAILED;
            $detail = substr($e->getMessage(), 0, 480);
        }

        $this->record($employee, $year, $status, null, $detail, $actorId, PayslipDelivery::CHANNEL_PORTAL);
    }

    /** A personal address first — a P9 is filed long after the job ended. */
    private function addressFor(Employee $employee): ?string
    {
        $address = $employee->personal_email ?: $employee->work_email;

        return filled($address) ? $address : null;
    }

    private function record(
        Employee $employee,
        int $year,
        string $status,
        ?string $recipient,
        ?string $detail,
        ?int $actorId,
        string $channel,
    ): array {
        PayslipDelivery::updateOrCreate(
            [
                'document_type'     => PayslipDelivery::TYPE_P9,
                'payroll_period_id' => null,
                'tax_year'          => $year,
                'employee_id'       => $employee->id,
                'channel'           => $channel,
            ],
            [
                'status'    => $status,
                'recipient' => $recipient,
                'detail'    => $detail,
                'sent_at'   => $status === PayslipDelivery::STATUS_SENT ? now() : null,
                'sent_by'   => $actorId,
            ],
        );

        return [
            'employee_id' => $employee->id,
            'name'        => $employee->full_name,
            'status'      => $status,
            'recipient'   => $recipient,
            'detail'      => $detail,
        ];
    }
}
