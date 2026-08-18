<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollPeriod;
use App\Services\Payroll\PayrollService;
use Illuminate\Http\JsonResponse;
use App\Models\P9Report;
use App\Models\PayslipDelivery;
use App\Services\Payroll\P9DeliveryService;
use App\Services\Payroll\PayslipDeliveryService;
use App\Services\Payroll\PayslipPdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PayrollController extends Controller
{
    public function __construct(private readonly PayrollService $service) {}

    // ── Periods ───────────────────────────────────────────────────────────────

    /** GET /api/v1/payroll */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PayrollPeriod::class);
        $orgId   = app('current.organization_id');
        $periods = $this->service->listPeriods($orgId, $request->integer('per_page', 20));
        return response()->json($periods);
    }

    /** POST /api/v1/payroll */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', PayrollPeriod::class);
        $data = $request->validate([
            'name'         => 'required|string|max:80',
            'start_date'   => 'required|date',
            'end_date'     => 'required|date|after_or_equal:start_date',
            'payment_date' => 'nullable|date',
        ]);
        $orgId  = app('current.organization_id');
        $period = $this->service->createPeriod($orgId, $data);
        return response()->json($period, 201);
    }

    /** GET /api/v1/payroll/{payrollPeriod} */
    public function show(int $payrollPeriod): JsonResponse
    {
        $this->authorize('view', PayrollPeriod::class);
        $period = $this->service->getPeriod($payrollPeriod);

        /*
         * What has already gone out.
         *
         * Without it the period screen looks identical before and after a send,
         * and the natural answer to "did that work?" is to press it again —
         * which puts a second copy of a salary in everybody's inbox.
         */
        $deliveries = PayslipDelivery::where('payroll_period_id', $period->id)
            ->where('channel', PayslipDelivery::CHANNEL_EMAIL)
            ->get(['status', 'sent_at']);

        return response()->json([
            // Built from the configuration the period was computed under, so a
            // payslip can never describe a calculation nobody performed.
            'rates'    => $this->service->rateLabelsFor($period),
            'delivery' => [
                'sent'      => $deliveries->where('status', PayslipDelivery::STATUS_SENT)->count(),
                'held'      => $deliveries->where('status', PayslipDelivery::STATUS_HELD)->count(),
                'failed'    => $deliveries->where('status', PayslipDelivery::STATUS_FAILED)->count(),
                'last_sent' => $deliveries->max('sent_at')?->toDateTimeString(),
            ],
        ] + $period->toArray());
    }

    /** POST /api/v1/payroll/{payrollPeriod}/process */
    public function process(int $payrollPeriod): JsonResponse
    {
        $this->authorize('process', PayrollPeriod::class);
        $period = $this->service->processPeriod($payrollPeriod);
        return response()->json($period);
    }

    /** POST /api/v1/payroll/{payrollPeriod}/approve */
    public function approve(int $payrollPeriod): JsonResponse
    {
        $this->authorize('approve', PayrollPeriod::class);
        $period = $this->service->approvePeriod($payrollPeriod);
        return response()->json($period);
    }

    /** POST /api/v1/payroll/{payrollPeriod}/mark-paid */
    public function markPaid(int $payrollPeriod): JsonResponse
    {
        $this->authorize('process', PayrollPeriod::class);
        $period = $this->service->markPaid($payrollPeriod);
        return response()->json($period);
    }

    // ── Individual slip ───────────────────────────────────────────────────────

    /** GET /api/v1/payroll/{payrollPeriod}/slip/{employee} */
    public function slip(int $payrollPeriod, int $employee): JsonResponse
    {
        $this->authorize('view', PayrollPeriod::class);
        $run = $this->service->getSlip($payrollPeriod, $employee);
        return response()->json($run);
    }

    /**
     * The payslip as a PDF, always encrypted.
     *
     * There is no unprotected variant and no flag to request one — see
     * PayslipPdf for why. A download and an email attachment are the same
     * bytes from the same door.
     */
    public function slipPdf(int $payrollPeriod, int $employee, PayslipPdf $pdf): Response
    {
        $this->authorize('view', PayrollPeriod::class);

        $run = $this->service->getSlip($payrollPeriod, $employee);

        try {
            $document = $pdf->render($run);
        } catch (\RuntimeException $e) {
            // Names the person and what is missing, because that is a record
            // somebody can correct rather than a failure they must investigate.
            abort(422, $e->getMessage());
        }

        return response($document['content'], 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $document['filename'] . '"',
            // The hint travels in a header so a client can tell the recipient
            // what to type without the password itself ever being sent.
            'X-Payslip-Password-Hint' => $document['password_hint'],
            'X-Content-Type-Options'  => 'nosniff',
        ]);
    }

    /** Who could be sent a payslip for this period, and who could not. */
    public function deliveryRecipients(int $payrollPeriod, PayslipDeliveryService $delivery): JsonResponse
    {
        $this->authorize('view', PayrollPeriod::class);

        $period = $this->service->getPeriod($payrollPeriod);

        return response()->json([
            'recipients' => $delivery->recipients($period),
            'sent'       => PayslipDelivery::where('document_type', PayslipDelivery::TYPE_PAYSLIP)
                ->where('payroll_period_id', $period->id)
                ->get(['employee_id', 'status', 'recipient', 'sent_at', 'detail']),
        ]);
    }

    /**
     * Send them.
     *
     * Guarded on the period being approved or paid: a draft is a working
     * document that can still be recomputed, and a payslip somebody has been
     * emailed cannot be.
     */
    public function sendPayslips(Request $request, int $payrollPeriod, PayslipDeliveryService $delivery): JsonResponse
    {
        $this->authorize('update', PayrollPeriod::class);

        $data = $request->validate([
            'employee_ids'   => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer'],
            'subject'        => ['nullable', 'string', 'max:200'],
            'message'        => ['nullable', 'string', 'max:2000'],
            // A note in the portal as well as the email. Only reaches employees
            // whose record is linked to a login.
            'portal'         => ['boolean'],
        ]);

        $period = $this->service->getPeriod($payrollPeriod);

        abort_unless(
            in_array($period->status, [PayrollPeriod::STATUS_APPROVED, PayrollPeriod::STATUS_PAID], true),
            422,
            'Approve the period before sending payslips — a draft can still be recomputed, and an emailed payslip cannot.',
        );

        return response()->json($delivery->send(
            $period,
            $data['employee_ids'],
            [
                'subject' => $data['subject'] ?? null,
                'message' => $data['message'] ?? null,
                'portal'  => (bool) ($data['portal'] ?? false),
            ],
            $request->user()?->id,
        ));
    }

    // ── P9 ────────────────────────────────────────────────────────────────────

    /** The years, as records with a state rather than a query somebody ran. */
    public function p9Reports(): JsonResponse
    {
        $this->authorize('view', PayrollPeriod::class);

        $orgId = app('current.organization_id');

        /*
         * Every year that has payroll behind it gets a row, created on sight.
         * A year with approved runs and no P9 record is not "nothing to see" —
         * it is a P9 nobody has started, which is the thing worth showing.
         */
        $years = PayrollPeriod::withoutGlobalScope('organization')
            ->where('organization_id', $orgId)
            ->whereIn('status', [PayrollPeriod::STATUS_APPROVED, PayrollPeriod::STATUS_PAID])
            ->selectRaw('distinct extract(year from start_date)::int as y')
            ->pluck('y');

        foreach ($years as $year) {
            P9Report::forYear($orgId, (int) $year);
        }

        $sent = PayslipDelivery::where('document_type', PayslipDelivery::TYPE_P9)
            ->where('status', PayslipDelivery::STATUS_SENT)
            ->where('channel', PayslipDelivery::CHANNEL_EMAIL)
            ->whereIn('employee_id', function ($q) use ($orgId) {
                $q->select('id')->from('employees')->where('primary_organization_id', $orgId);
            })
            ->selectRaw('tax_year, count(*) as c')
            ->groupBy('tax_year')
            ->pluck('c', 'tax_year');

        return response()->json([
            'data' => P9Report::withoutGlobalScope('organization')
                ->where('organization_id', $orgId)
                ->orderByDesc('tax_year')
                ->get()
                ->map(fn (P9Report $r) => [
                    'id'             => $r->id,
                    'reference'      => $r->reference,
                    'tax_year'       => $r->tax_year,
                    'employee_count' => $r->employee_count,
                    'status'         => $r->status,
                    'generated_at'   => $r->generated_at?->toDateTimeString(),
                    'last_updated'   => $r->updated_at?->toDateTimeString(),
                    // How many people actually have it. The count, not a flag:
                    // "sent" over a part-finished year is the misleading answer.
                    'sent_count'     => $sent[$r->tax_year] ?? 0,
                ]),
        ]);
    }

    /**
     * Who has a P9 for this year, and who has already had it.
     *
     * Same shape as the payslip endpoint, because the send screen is the same
     * screen: it takes a list of people, a note about a locked attachment, and
     * a record of what happened last time.
     */
    public function p9Recipients(int $year, P9DeliveryService $delivery): JsonResponse
    {
        $this->authorize('view', PayrollPeriod::class);

        $orgId = app('current.organization_id');

        return response()->json([
            'recipients' => $delivery->recipients($orgId, $year),
            'sent'       => PayslipDelivery::where('document_type', PayslipDelivery::TYPE_P9)
                ->where('tax_year', $year)
                ->whereIn('employee_id', function ($q) use ($orgId) {
                    $q->select('id')->from('employees')->where('primary_organization_id', $orgId);
                })
                ->get(['employee_id', 'status', 'recipient', 'sent_at', 'detail']),
        ]);
    }

    /**
     * Send them.
     *
     * Not guarded on the report being marked completed. "Completed" is a note
     * somebody leaves for the next person, not a lock — and a P9 that has to be
     * reissued because a record was corrected is the normal case, not an
     * exception to work around.
     */
    public function sendP9(Request $request, int $year, P9DeliveryService $delivery): JsonResponse
    {
        $this->authorize('update', PayrollPeriod::class);

        $data = $request->validate([
            'employee_ids'   => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer'],
            'subject'        => ['nullable', 'string', 'max:200'],
            'message'        => ['nullable', 'string', 'max:2000'],
            'portal'         => ['boolean'],
        ]);

        $orgId = app('current.organization_id');

        $result = $delivery->send($orgId, $year, $data['employee_ids'], [
            'subject' => $data['subject'] ?? null,
            'message' => $data['message'] ?? null,
            'portal'  => (bool) ($data['portal'] ?? false),
        ], $request->user()?->id);

        // The year's record carries when it last went out, so the reports list
        // can say so without counting rows every time it loads.
        if ($result['sent'] > 0) {
            P9Report::forYear($orgId, $year)->forceFill(['generated_at' => now()])->save();
        }

        return response()->json($result);
    }

    /** One employee's year, month by month — the shape of the actual P9A form. */
    public function p9Months(Request $request, int $employee): JsonResponse
    {
        $this->authorize('view', PayrollPeriod::class);

        $year  = $request->integer('year', now()->year);
        $orgId = app('current.organization_id');

        return response()->json([
            'year'   => $year,
            'months' => $this->service->getP9Months($orgId, $year, $employee),
        ]);
    }

    /**
     * Mark a year's P9 finished.
     *
     * Not a lock — the figures still come from the payroll runs and would change
     * if a period were ever reopened. It records that somebody has checked it,
     * which is what the status on the designs means and all it can mean.
     */
    public function completeP9(Request $request, int $year): JsonResponse
    {
        $this->authorize('update', PayrollPeriod::class);

        $record = P9Report::forYear(app('current.organization_id'), $year);
        $record->update([
            'status'       => P9Report::STATUS_COMPLETED,
            'completed_at' => now(),
            'completed_by' => $request->user()?->employeeId(),
        ]);

        return response()->json(['data' => $record->fresh()]);
    }

    /** GET /api/v1/payroll/p9?year=2025 */
    public function p9(Request $request): JsonResponse
    {
        $year  = $request->integer('year', now()->year);
        $orgId = app('current.organization_id');
        $data  = $this->service->getP9Report($orgId, $year);

        /*
         * Reading it counts as generating it: the figures are computed fresh
         * every time, so "last generated" and "last read" are the same moment.
         */
        $record = P9Report::forYear($orgId, $year);
        $record->update([
            'employee_count' => count($data),
            'generated_at'   => now(),
            'generated_by'   => request()->user()?->id,
        ]);
        return response()->json(['year' => $year, 'employees' => $data]);
    }

    // ── Pay history (employee self-service) ───────────────────────────────────

    /** GET /api/v1/payroll/history */
    public function history(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        abort_if(! $employee, 422, 'User has no linked employee record.');
        $runs = $this->service->getPayHistory($employee->id, $request->integer('per_page', 12));
        return response()->json($runs);
    }
}
