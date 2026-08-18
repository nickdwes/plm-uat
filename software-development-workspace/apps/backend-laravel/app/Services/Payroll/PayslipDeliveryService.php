<?php

namespace App\Services\Payroll;

use App\Mail\PayslipMail;
use App\Models\Employee;
use App\Models\PayrollDocumentSetting;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\PayslipDelivery;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Getting payslips to people.
 *
 * ── Nothing is sent that could not be locked ──
 *
 * The password is derived per employee, and where it cannot be derived the
 * payslip is *held*, recorded with the reason, and no email is produced. There
 * is no path here that emails an open document, which is the same rule
 * PayslipPdf enforces and this repeats rather than assumes.
 *
 * ── One failure does not stop the run ──
 *
 * A hundred and fifty payslips, and the fortieth address bounces. Aborting
 * would leave thirty-nine people paid and informed and everybody else
 * wondering. Each is attempted and recorded on its own, and the caller gets a
 * tally at the end.
 */
class PayslipDeliveryService
{
    public function __construct(
        private readonly PayslipPdf $pdf,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Who could be sent one, and where it would go.
     *
     * Includes the people who cannot be — with the reason — because the point
     * of showing this before sending is to find them while it is still cheap.
     */
    public function recipients(PayrollPeriod $period): array
    {
        $settings = PayrollDocumentSetting::forOrganization($period->organization_id);

        return $period->runs()->with('employee.department')->get()
            ->map(function (PayrollRun $run) use ($settings) {
                $employee = $run->employee;

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
                    'net_pay'         => (float) $run->net_pay,
                    /*
                     * Two different problems with two different fixes: no
                     * address is an email to chase, no password source is a
                     * record to complete. Collapsing them into "cannot send"
                     * would send somebody looking in the wrong place.
                     */
                    'sendable'        => $address !== null && $canLock,
                    'blocked_reason'  => match (true) {
                        $address === null => 'No email address on file',
                        ! $canLock        => 'Nothing to build a payslip password from',
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
     * Send to the employees named, and record every outcome.
     *
     * @param  array $options subject, message, portal
     * @return array{sent: int, held: int, failed: int, results: array}
     */
    public function send(PayrollPeriod $period, array $employeeIds, array $options = [], ?int $actorId = null): array
    {
        $settings = PayrollDocumentSetting::forOrganization($period->organization_id);
        $org      = $period->organization?->name ?? '';

        $runs = $period->runs()
            ->whereIn('employee_id', $employeeIds)
            ->with(['employee', 'period.organization'])
            ->get();

        $results = [];

        foreach ($runs as $run) {
            $employee = $run->employee;

            if (! $employee) {
                continue;
            }

            $results[] = $this->deliverOne($run, $employee, $settings, $period, $org, $options, $actorId);

            /*
             * A note in the portal, where the payslip can be opened without an
             * email at all. Recorded on its own channel: somebody can be
             * reachable in the portal and not by email, and a single "sent"
             * would hide which.
             */
            if (($options['portal'] ?? false) && $employee->user_id) {
                $this->notifyInPortal($period, $employee, $actorId);
            }
        }

        return [
            'sent'    => collect($results)->where('status', PayslipDelivery::STATUS_SENT)->count(),
            'held'    => collect($results)->where('status', PayslipDelivery::STATUS_HELD)->count(),
            'failed'  => collect($results)->where('status', PayslipDelivery::STATUS_FAILED)->count(),
            'results' => $results,
        ];
    }

    private function notifyInPortal(PayrollPeriod $period, Employee $employee, ?int $actorId): void
    {
        try {
            $this->notifications->create(
                organizationId: (string) $period->organization_id,
                userId:         $employee->user_id,
                type:           'payslip',
                title:          "Your payslip for {$period->name} is ready",
                message:        'Open it from Payroll — My Pay History.',
                data:           ['payroll_period_id' => $period->id],
            );

            $status = PayslipDelivery::STATUS_SENT;
            $detail = null;
        } catch (Throwable $e) {
            $status = PayslipDelivery::STATUS_FAILED;
            $detail = substr($e->getMessage(), 0, 480);
        }

        PayslipDelivery::updateOrCreate(
            [
                'payroll_period_id' => $period->id,
                'employee_id'       => $employee->id,
                'channel'           => PayslipDelivery::CHANNEL_PORTAL,
            ],
            [
                'status'    => $status,
                'recipient' => null,
                'detail'    => $detail,
                'sent_at'   => $status === PayslipDelivery::STATUS_SENT ? now() : null,
                'sent_by'   => $actorId,
            ],
        );
    }

    private function deliverOne(
        PayrollRun $run,
        Employee $employee,
        PayrollDocumentSetting $settings,
        PayrollPeriod $period,
        string $org,
        array $options,
        ?int $actorId,
    ): array {
        $address = $this->addressFor($employee);

        if ($address === null) {
            return $this->record($period, $employee, PayslipDelivery::STATUS_HELD, null,
                'No email address on file', $actorId);
        }

        try {
            // Encrypted here, before anything can be attached to anything.
            $document = $this->pdf->render($run);
        } catch (Throwable $e) {
            /*
             * Held rather than failed. Nothing was sent, nothing will be by
             * retrying, and somebody has a record to complete.
             */
            return $this->record($period, $employee, PayslipDelivery::STATUS_HELD, $address,
                $e->getMessage(), $actorId);
        }

        try {
            Mail::to($address)->send(new PayslipMail(
                employeeName:     $employee->full_name,
                periodName:       $period->name,
                organisationName: $org,
                passwordHint:     $document['password_hint'],
                pdf:              $document['content'],
                filename:         $document['filename'],
                subjectLine:      $options['subject'] ?? null,
                bodyText:         $options['message'] ?? null,
            ));
        } catch (Throwable $e) {
            return $this->record($period, $employee, PayslipDelivery::STATUS_FAILED, $address,
                substr($e->getMessage(), 0, 480), $actorId);
        }

        return $this->record($period, $employee, PayslipDelivery::STATUS_SENT, $address, null, $actorId);
    }

    /**
     * A personal address first.
     *
     * A payslip outlives the job it came from: somebody applying for a mortgage
     * six months after leaving still needs it, and their work mailbox is gone.
     */
    private function addressFor(Employee $employee): ?string
    {
        $address = $employee->personal_email ?: $employee->work_email;

        return filled($address) ? $address : null;
    }

    /** One row per employee per channel, overwritten when it is tried again. */
    private function record(
        PayrollPeriod $period,
        Employee $employee,
        string $status,
        ?string $recipient,
        ?string $detail,
        ?int $actorId,
    ): array {
        PayslipDelivery::updateOrCreate(
            [
                'payroll_period_id' => $period->id,
                'employee_id'       => $employee->id,
                'channel'           => PayslipDelivery::CHANNEL_EMAIL,
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
