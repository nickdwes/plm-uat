<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollDeductionCode;
use App\Models\PayrollEarningCode;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\PayrollRunItem;
use App\Services\Payroll\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ad-hoc lines on one person's run: overtime, a bonus, an advance recovery.
 *
 * The engine has read these since the table was created; nothing could write
 * one. That made every run a straight function of the employee's standing
 * salary — no overtime, no bonus, no one-off correction — which is not a
 * payroll anybody can actually run a month with.
 *
 * ── Why every write recomputes ──
 *
 * A line that does not change the net pay is decoration. Adding overtime and
 * leaving the run untouched until somebody remembers to re-process is exactly
 * how a payslip goes out disagreeing with the line printed on it.
 *
 * ── Why they stop at approval ──
 *
 * Once a period is approved the payslips may already be in inboxes and the P9
 * behind them is being built from these figures. Changing a run after that
 * leaves a document in somebody's hands that the system no longer agrees with.
 */
class PayrollRunItemController extends Controller
{
    public function __construct(private readonly PayrollService $service) {}

    public function index(int $run): JsonResponse
    {
        $this->authorize('view', PayrollPeriod::class);

        $payrollRun = $this->run($run);

        return response()->json([
            'data'     => $this->itemsFor($payrollRun),
            'editable' => $this->isOpen($payrollRun->period),
            'totals'   => $this->totals($payrollRun),
        ]);
    }

    public function store(Request $request, int $run): JsonResponse
    {
        $this->authorize('update', PayrollPeriod::class);

        $payrollRun = $this->run($run);
        $this->assertOpen($payrollRun->period);

        $data = $this->validated($request);

        $item = DB::transaction(function () use ($data, $payrollRun, $request) {
            $item = PayrollRunItem::create($data + [
                'payroll_run_id' => $payrollRun->id,
                'created_by'     => $request->user()?->id,
            ]);

            $this->service->recomputeRun($payrollRun->payroll_period_id, $payrollRun->employee_id);

            return $item;
        });

        return response()->json([
            'data'   => $item->fresh(),
            'run'    => $payrollRun->fresh(),
            'totals' => $this->totals($payrollRun->fresh()),
        ], 201);
    }

    public function update(Request $request, int $run, int $item): JsonResponse
    {
        $this->authorize('update', PayrollPeriod::class);

        $payrollRun = $this->run($run);
        $this->assertOpen($payrollRun->period);

        $line = PayrollRunItem::where('payroll_run_id', $payrollRun->id)->findOrFail($item);
        $data = $this->validated($request);

        DB::transaction(function () use ($line, $data, $payrollRun) {
            $line->update($data);
            $this->service->recomputeRun($payrollRun->payroll_period_id, $payrollRun->employee_id);
        });

        return response()->json([
            'data'   => $line->fresh(),
            'run'    => $payrollRun->fresh(),
            'totals' => $this->totals($payrollRun->fresh()),
        ]);
    }

    public function destroy(int $run, int $item): JsonResponse
    {
        $this->authorize('update', PayrollPeriod::class);

        $payrollRun = $this->run($run);
        $this->assertOpen($payrollRun->period);

        $line = PayrollRunItem::where('payroll_run_id', $payrollRun->id)->findOrFail($item);

        DB::transaction(function () use ($line, $payrollRun) {
            $line->delete();
            $this->service->recomputeRun($payrollRun->payroll_period_id, $payrollRun->employee_id);
        });

        return response()->json([
            'run'    => $payrollRun->fresh(),
            'totals' => $this->totals($payrollRun->fresh()),
        ]);
    }

    /** The codes an ad-hoc line can be raised against. */
    public function codes(): JsonResponse
    {
        $this->authorize('view', PayrollPeriod::class);

        return response()->json([
            'earnings' => PayrollEarningCode::where('is_active', true)
                ->orderBy('name')->get(['id', 'code', 'name', 'is_taxable']),
            'deductions' => PayrollDeductionCode::where('is_active', true)
                ->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Validate, and settle how the line is taxed at the moment it is written.
     *
     * Taken from the code rather than trusted from the client — whether a per
     * diem is taxed is a matter of law, not something a form should be able to
     * assert. Copied onto the row rather than read through later, so a code
     * edited in March does not re-tax a payslip issued in January.
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'type'   => ['required', 'in:earning,deduction'],
            'label'  => ['required', 'string', 'max:120'],
            // Signed amounts would let a "deduction" of -5,000 pay somebody.
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'note'   => ['nullable', 'string', 'max:200'],
            'payroll_earning_code_id'   => ['nullable', 'integer', 'exists:payroll_earning_codes,id'],
            'payroll_deduction_code_id' => ['nullable', 'integer', 'exists:payroll_deduction_codes,id'],
        ]);

        if ($data['type'] === PayrollRunItem::TYPE_EARNING) {
            $data['payroll_deduction_code_id'] = null;

            $code = isset($data['payroll_earning_code_id'])
                ? PayrollEarningCode::find($data['payroll_earning_code_id'])
                : null;

            // No code means an uncoded one-off, which is taxed: treating an
            // unclassified payment as tax-free is the error that costs the
            // employer, and the employee, at the next KRA audit.
            $data['is_taxable'] = $code ? (bool) $code->is_taxable : true;
        } else {
            $data['payroll_earning_code_id'] = null;
            $data['is_taxable'] = true;   // Immaterial on a deduction.
        }

        return $data;
    }

    private function run(int $run): PayrollRun
    {
        $orgId = app()->bound('current.organization_id') ? app('current.organization_id') : null;

        return PayrollRun::with(['period', 'items'])
            ->whereHas('period', fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($run);
    }

    private function isOpen(PayrollPeriod $period): bool
    {
        return in_array($period->status, [
            PayrollPeriod::STATUS_DRAFT,
            PayrollPeriod::STATUS_PROCESSING,
        ], true);
    }

    private function assertOpen(PayrollPeriod $period): void
    {
        abort_unless(
            $this->isOpen($period),
            422,
            "'{$period->name}' has been approved. Payslips may already have gone out, "
            . 'and a run changed underneath one leaves somebody holding a document this system no longer agrees with.',
        );
    }

    private function itemsFor(PayrollRun $run): array
    {
        return $run->items()->orderBy('type')->orderBy('id')->get()->map(fn (PayrollRunItem $i) => [
            'id'          => $i->id,
            'type'        => $i->type,
            'label'       => $i->label,
            'amount'      => (float) $i->amount,
            'is_taxable'  => (bool) $i->is_taxable,
            'note'        => $i->note,
            'earning_code_id'   => $i->payroll_earning_code_id,
            'deduction_code_id' => $i->payroll_deduction_code_id,
        ])->all();
    }

    private function totals(PayrollRun $run): array
    {
        return [
            'gross_pay'            => (float) $run->gross_pay,
            'non_taxable_earnings' => (float) $run->non_taxable_earnings,
            'total_deductions'     => (float) $run->total_deductions,
            'net_pay'              => (float) $run->net_pay,
        ];
    }
}
