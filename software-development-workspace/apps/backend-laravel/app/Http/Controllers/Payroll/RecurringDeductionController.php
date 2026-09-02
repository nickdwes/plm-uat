<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeRecurringDeduction;
use App\Models\PayrollDeductionCode;
use App\Models\PayrollPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Standing orders against pay: SACCO check-offs, HELB, advances, court orders.
 *
 * Set up once and recovered by every subsequent run, which is the difference
 * between a payroll somebody can operate monthly and one where each recovery is
 * retyped from memory.
 *
 * Balances are never accepted from the client and never written. What is
 * outstanding is what the principal was, less what has actually come off
 * approved payroll — so the figure on this screen and the figures on the
 * payslips behind it cannot disagree.
 */
class RecurringDeductionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PayrollPeriod::class);

        $query = EmployeeRecurringDeduction::with(['code', 'employee']);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json([
            'data' => $query->orderBy('priority')->orderBy('id')->get()
                ->map(fn (EmployeeRecurringDeduction $d) => $this->present($d)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('update', PayrollPeriod::class);

        $data = $this->validated($request);

        $deduction = EmployeeRecurringDeduction::create($data + [
            'organization_id' => app('current.organization_id'),
            'created_by'      => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->present($deduction->fresh(['code', 'employee']))], 201);
    }

    public function update(Request $request, EmployeeRecurringDeduction $recurringDeduction): JsonResponse
    {
        $this->authorize('update', PayrollPeriod::class);

        $recurringDeduction->update($this->validated($request, $recurringDeduction));

        return response()->json(['data' => $this->present($recurringDeduction->fresh(['code', 'employee']))]);
    }

    /**
     * Stop it.
     *
     * Suspended rather than deleted where anything has been recovered: those
     * lines are the repayment history, and a schedule removed from under them
     * leaves a loan with payments and no agreement.
     */
    public function destroy(EmployeeRecurringDeduction $recurringDeduction): JsonResponse
    {
        $this->authorize('update', PayrollPeriod::class);

        if ($recurringDeduction->recovered() > 0) {
            $recurringDeduction->update(['status' => EmployeeRecurringDeduction::STATUS_SUSPENDED]);

            return response()->json([
                'data'    => $this->present($recurringDeduction->fresh(['code', 'employee'])),
                'message' => 'Suspended rather than deleted — money has already been recovered against it, '
                    . 'and that history has to keep something to belong to.',
            ]);
        }

        $recurringDeduction->delete();

        return response()->json(['deleted' => true]);
    }

    public function codes(): JsonResponse
    {
        $this->authorize('viewAny', PayrollPeriod::class);

        return response()->json([
            'data' => PayrollDeductionCode::where('is_active', true)
                ->orderBy('priority')
                ->get(['id', 'code', 'name', 'deduction_type', 'priority', 'recover_balance']),
        ]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function validated(Request $request, ?EmployeeRecurringDeduction $existing = null): array
    {
        $data = $request->validate([
            'employee_id' => [$existing ? 'sometimes' : 'required', 'integer', 'exists:employees,id'],
            'payroll_deduction_code_id' => [$existing ? 'sometimes' : 'required', 'integer', 'exists:payroll_deduction_codes,id'],
            'label'      => ['nullable', 'string', 'max:120'],
            'instalment' => [$existing ? 'sometimes' : 'required', 'numeric', 'min:0.01', 'max:99999999'],
            // Null means a standing check-off that runs until stopped, rather
            // than a loan that pays itself off.
            'principal'  => ['nullable', 'numeric', 'min:0.01', 'max:999999999'],
            'starts_on'  => [$existing ? 'sometimes' : 'required', 'date'],
            'ends_on'    => ['nullable', 'date', 'after_or_equal:starts_on'],
            'status'     => ['nullable', 'in:active,suspended,completed'],
            'priority'   => ['nullable', 'integer', 'min:1', 'max:999'],
            'reference'  => ['nullable', 'string', 'max:80'],
            'note'       => ['nullable', 'string', 'max:200'],
        ]);

        $codeId = $data['payroll_deduction_code_id'] ?? $existing?->payroll_deduction_code_id;
        $code   = $codeId ? PayrollDeductionCode::find($codeId) : null;

        // The label and the ordering default to the code, so the common case is
        // a code, an amount and a date — not a form full of decisions.
        if (empty($data['label'])) {
            $data['label'] = $existing?->label ?: ($code->name ?? 'Deduction');
        }

        if (! isset($data['priority'])) {
            $data['priority'] = $existing?->priority ?? ($code->priority ?? 50);
        }

        if (isset($data['employee_id'])) {
            // Scoped: an employee id from another tenant must not be assignable
            // by guessing it.
            Employee::findOrFail($data['employee_id']);
        }

        return $data;
    }

    private function present(EmployeeRecurringDeduction $d): array
    {
        $outstanding = $d->outstanding();

        return [
            'id'          => $d->id,
            'employee_id' => $d->employee_id,
            'employee'    => $d->employee?->full_name,
            'code'        => $d->code?->name,
            'code_id'     => $d->payroll_deduction_code_id,
            'label'       => $d->label,
            'instalment'  => (float) $d->instalment,
            'principal'   => $d->principal === null ? null : (float) $d->principal,
            // Derived on read, never stored: the payslips are the record.
            'recovered'   => $d->recovered(),
            'outstanding' => $outstanding,
            'starts_on'   => $d->starts_on?->toDateString(),
            'ends_on'     => $d->ends_on?->toDateString(),
            // Follows the balance: a settled loan does not read "active".
            'status'      => $d->effectiveStatus(),
            'priority'    => $d->priority,
            'reference'   => $d->reference,
            'note'        => $d->note,
        ];
    }
}
