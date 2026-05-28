<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Services\Payroll\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    public function __construct(private readonly PayrollService $service) {}

    // ── Periods ───────────────────────────────────────────────────────────────

    /** GET /api/v1/payroll */
    public function index(Request $request): JsonResponse
    {
        $orgId   = app('current.organization_id');
        $periods = $this->service->listPeriods($orgId, $request->integer('per_page', 20));
        return response()->json($periods);
    }

    /** POST /api/v1/payroll */
    public function store(Request $request): JsonResponse
    {
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
        $period = $this->service->getPeriod($payrollPeriod);
        return response()->json($period);
    }

    /** POST /api/v1/payroll/{payrollPeriod}/process */
    public function process(int $payrollPeriod): JsonResponse
    {
        $period = $this->service->processPeriod($payrollPeriod);
        return response()->json($period);
    }

    /** POST /api/v1/payroll/{payrollPeriod}/approve */
    public function approve(int $payrollPeriod): JsonResponse
    {
        $period = $this->service->approvePeriod($payrollPeriod);
        return response()->json($period);
    }

    /** POST /api/v1/payroll/{payrollPeriod}/mark-paid */
    public function markPaid(int $payrollPeriod): JsonResponse
    {
        $period = $this->service->markPaid($payrollPeriod);
        return response()->json($period);
    }

    // ── Individual slip ───────────────────────────────────────────────────────

    /** GET /api/v1/payroll/{payrollPeriod}/slip/{employee} */
    public function slip(int $payrollPeriod, int $employee): JsonResponse
    {
        $run = $this->service->getSlip($payrollPeriod, $employee);
        return response()->json($run);
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
