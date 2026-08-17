<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollPeriod;
use App\Services\Payroll\PayrollService;
use Illuminate\Http\JsonResponse;
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
        return response()->json($period);
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

    // ── P9 ────────────────────────────────────────────────────────────────────

    /** GET /api/v1/payroll/p9?year=2025 */
    public function p9(Request $request): JsonResponse
    {
        $year  = $request->integer('year', now()->year);
        $orgId = app('current.organization_id');
        $data  = $this->service->getP9Report($orgId, $year);
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
