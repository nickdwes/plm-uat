<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollConfigStatutoryRate;
use App\Models\PayrollConfigTaxBand;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Repositories\Payroll\PayrollRepository;
use App\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PayrollService extends BaseService
{
    public function __construct(PayrollRepository $repository)
    {
        parent::__construct($repository);
    }

    // ── Periods ───────────────────────────────────────────────────────────────

    public function listPeriods(int $orgId, int $perPage = 20): LengthAwarePaginator
    {
        return PayrollPeriod::withoutGlobalScope('organization')
            ->where('organization_id', $orgId)
            ->withCount('runs')
            ->latest('start_date')
            ->paginate($perPage);
    }

    public function createPeriod(int $orgId, array $data): PayrollPeriod
    {
        return PayrollPeriod::create([
            'organization_id' => $orgId,
            'name'            => $data['name'],
            'start_date'      => $data['start_date'],
            'end_date'        => $data['end_date'],
            'payment_date'    => $data['payment_date'] ?? null,
            'status'          => PayrollPeriod::STATUS_DRAFT,
        ]);
    }

    public function getPeriod(int $periodId): PayrollPeriod
    {
        return PayrollPeriod::with([
            'runs.employee.department',
            'runs.employee.designation',
        ])->findOrFail($periodId);
    }

    /**
     * The rates this period was computed under, described for a payslip.
     *
     * The screen used to spell them out by hand — "first KES 7,000", "next KES
     * 29,000" — and the moment the limits were corrected the payslip described
     * a calculation nobody had performed. A number typed into a label is a
     * number that goes stale silently, so the labels are built from the same
     * configuration the arithmetic used.
     */
    public function rateLabelsFor(PayrollPeriod $period): array
    {
        $config = $this->loadPayrollConfig($period->organization_id, $period);

        $money = fn ($v) => number_format((float) $v, 0);
        $pct   = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');

        $tier1 = (float) ($config['nssf_tier1_ceiling'] ?? 8000);
        $tier2 = (float) ($config['nssf_tier2_ceiling'] ?? 72000);
        $nssf  = (float) ($config['nssf_employee_rate'] ?? 6);

        return [
            'shif'         => 'SHIF (' . $pct($config['shif_rate'] ?? 2.75) . '% of gross)',
            'nssf_tier1'   => 'NSSF Tier I (' . $pct($nssf) . '% of first KES ' . $money($tier1) . ')',
            'nssf_tier2'   => 'NSSF Tier II (' . $pct($nssf) . '% of the next KES ' . $money($tier2 - $tier1) . ')',
            'housing_levy' => 'Affordable Housing Levy (' . $pct($config['housing_levy_employee_rate'] ?? 1.5) . '%)',
            /*
             * Shown apart from the deductions because it is not one. The levy
             * is the employer's, and a payslip line for money the employee did
             * not pay starts an argument it cannot settle.
             */
            'nita'         => 'NITA training levy (employer)',
            'nita_on_employee' => (int) ($config['nita_on_employee'] ?? 0) === 1,
        ];
    }

    // ── Process ───────────────────────────────────────────────────────────────

    /**
     * Compute Kenya statutory deductions for every active employee and
     * upsert PayrollRun records.
     */
    public function processPeriod(int $periodId): PayrollPeriod
    {
        return DB::transaction(function () use ($periodId) {
            $period = PayrollPeriod::findOrFail($periodId);

            if (! $period->isDraft()) {
                throw new \DomainException("Period '{$period->name}' is already processed or closed.");
            }

            $period->update(['status' => PayrollPeriod::STATUS_PROCESSING]);

            $employees = Employee::withoutGlobalScope('organization')
                ->where('primary_organization_id', $period->organization_id)
                ->where('status', 'active')
                ->whereNull('date_exited')
                ->get();

            foreach ($employees as $employee) {
                $this->computeRunForEmployee($period, $employee);
            }

            return $period->fresh(['runs.employee']);
        });
    }

    /**
     * Re-compute a single employee's run within a draft/processing period.
     */
    public function recomputeRun(int $periodId, int $employeeId): PayrollRun
    {
        $period   = PayrollPeriod::findOrFail($periodId);
        $employee = Employee::findOrFail($employeeId);

        if (! in_array($period->status, [PayrollPeriod::STATUS_DRAFT, PayrollPeriod::STATUS_PROCESSING])) {
            throw new \DomainException("Cannot re-compute a run on an approved or paid period.");
        }

        return $this->computeRunForEmployee($period, $employee);
    }

    // ── Approve / Mark Paid ───────────────────────────────────────────────────

    public function approvePeriod(int $periodId): PayrollPeriod
    {
        return DB::transaction(function () use ($periodId) {
            $period = PayrollPeriod::findOrFail($periodId);

            if ($period->status !== PayrollPeriod::STATUS_PROCESSING) {
                throw new \DomainException("Period must be 'processing' to approve.");
            }

            PayrollRun::where('payroll_period_id', $period->id)
                ->update(['status' => PayrollRun::STATUS_APPROVED]);

            $period->update(['status' => PayrollPeriod::STATUS_APPROVED]);

            return $period->fresh();
        });
    }

    public function markPaid(int $periodId): PayrollPeriod
    {
        return DB::transaction(function () use ($periodId) {
            $period = PayrollPeriod::findOrFail($periodId);

            if ($period->status !== PayrollPeriod::STATUS_APPROVED) {
                throw new \DomainException("Period must be 'approved' to mark as paid.");
            }

            PayrollRun::where('payroll_period_id', $period->id)
                ->update(['status' => PayrollRun::STATUS_PAID]);

            $period->update(['status' => PayrollPeriod::STATUS_PAID]);

            return $period->fresh();
        });
    }

    // ── Individual slip ───────────────────────────────────────────────────────

    public function getSlip(int $periodId, int $employeeId): PayrollRun
    {
        $orgId = app('current.organization_id');
        return PayrollRun::where('payroll_period_id', $periodId)
            ->where('employee_id', $employeeId)
            ->whereHas('period', fn($q) => $q->where('organization_id', $orgId))
            ->with(['employee.department', 'employee.designation', 'period', 'items'])
            ->firstOrFail();
    }

    // ── Pay history ───────────────────────────────────────────────────────────

    public function getPayHistory(int $employeeId, int $perPage = 12): LengthAwarePaginator
    {
        return PayrollRun::where('employee_id', $employeeId)
            ->with('period')
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * The year's P9, month by month, for one employee.
     *
     * This is the shape of the actual KRA P9A: a row per month with basic pay,
     * benefits and value of quarters, their total, and what was set against it.
     * The annual report sums these; nobody files a sum.
     *
     * The data has been here since the first payroll ran — one run per month —
     * and was never exposed, so the P9 could only ever be read as a year.
     */
    public function getP9Months(int $orgId, int $year, int $employeeId): array
    {
        $runs = PayrollRun::whereHas('period', fn ($q) => $q
                ->where('organization_id', $orgId)
                ->whereYear('start_date', $year)
                ->whereIn('status', [PayrollPeriod::STATUS_APPROVED, PayrollPeriod::STATUS_PAID]))
            ->where('employee_id', $employeeId)
            ->with('period')
            ->get()
            ->sortBy(fn ($r) => $r->period->start_date)
            ->values();

        return $runs->map(function (PayrollRun $r) {
            $basic    = (float) $r->basic_salary;
            /*
             * Column B on the form. Allowances that are not basic pay: house,
             * transport and anything else earned. Grouped because the form asks
             * for one figure, not the breakdown the payslip shows.
             */
            $benefits = (float) $r->house_allowance + (float) $r->transport_allowance + (float) $r->other_earnings;

            return [
                'month'          => $r->period->start_date?->format('F'),
                'period'         => $r->period->name,
                'basic_pay'      => round($basic, 2),
                'benefits'       => round($benefits, 2),
                // Housing provided by the employer. Not modelled yet, and shown
                // as nought rather than omitted: the form has the column, and a
                // missing column reads as an error rather than an absence.
                'value_of_quarters' => 0.0,
                'gross_pay'      => round((float) $r->gross_pay, 2),
                'defined_contribution' => round((float) $r->nssf_tier1 + (float) $r->nssf_tier2, 2),
                'taxable_income' => round(
                    (float) ($r->taxable_income ?? ($r->gross_pay - $r->nssf_tier1 - $r->nssf_tier2)), 2,
                ),
                'paye'             => round((float) $r->paye, 2),
                'personal_relief'  => round((float) $r->personal_relief, 2),
                'insurance_relief' => round((float) $r->insurance_relief, 2),
                'paye_payable'     => round((float) $r->paye_payable, 2),
                'shif'             => round((float) $r->shif, 2),
                'housing_levy'     => round((float) $r->housing_levy, 2),
                'net_pay'          => round((float) $r->net_pay, 2),
            ];
        })->all();
    }

    // ── P9 annual report ──────────────────────────────────────────────────────

    /**
     * Return P9-ready data for all employees in an org for a given year.
     * Groups payroll runs by employee and sums the year's figures.
     */
    public function getP9Report(int $orgId, int $year): array
    {
        $runs = PayrollRun::whereHas('period', function ($q) use ($orgId, $year) {
                $q->where('organization_id', $orgId)
                  ->whereYear('start_date', $year)
                  ->whereIn('status', [PayrollPeriod::STATUS_APPROVED, PayrollPeriod::STATUS_PAID]);
            })
            ->with(['employee', 'period'])
            ->get();

        $grouped = $runs->groupBy('employee_id');

        return $grouped->map(function ($empRuns) {
            $employee = $empRuns->first()->employee;
            return [
                'employee'         => [
                    'id'              => $employee->id,
                    'employee_number' => $employee->employee_number,
                    'full_name'       => $employee->full_name,
                    'tax_id'          => $employee->tax_id,
                    'nssf_number'     => $employee->nssf_number,
                    'nhif_number'     => $employee->nhif_number,
                ],
                'months'           => $empRuns->count(),
                'gross_pay'        => (float) $empRuns->sum('gross_pay'),
                /*
                 * The figure the tax was charged on. Older runs predate the
                 * column and fall back to the arithmetic in force when they
                 * were computed, which is what their PAYE was derived from.
                 */
                'taxable_income'   => (float) $empRuns->sum(
                    fn ($r) => $r->taxable_income ?? ($r->gross_pay - $r->nssf_tier1 - $r->nssf_tier2),
                ),
                'paye'             => (float) $empRuns->sum('paye'),
                'personal_relief'  => (float) $empRuns->sum('personal_relief'),
                'insurance_relief' => (float) $empRuns->sum('insurance_relief'),
                'paye_payable'     => (float) $empRuns->sum('paye_payable'),
                'shif'             => (float) $empRuns->sum('shif'),
                'nssf_tier1'       => (float) $empRuns->sum('nssf_tier1'),
                'nssf_tier2'       => (float) $empRuns->sum('nssf_tier2'),
                'housing_levy'     => (float) $empRuns->sum('housing_levy'),
                'net_pay'          => (float) $empRuns->sum('net_pay'),
            ];
        })->values()->all();
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function computeRunForEmployee(PayrollPeriod $period, Employee $employee): PayrollRun
    {
        $basic     = (float) ($employee->basic_salary         ?? 0);
        $house     = (float) ($employee->house_allowance      ?? 0);
        $transport = (float) ($employee->transport_allowance  ?? 0);
        $medical   = (float) ($employee->medical_allowance    ?? 0);
        $other     = (float) ($employee->other_allowances     ?? 0);
        $insurance = (float) ($employee->insurance_relief     ?? 0);  // monthly premiums

        // Gross = all fixed earnings
        $gross = $basic + $house + $transport + $medical + $other;

        // Fetch any existing ad-hoc run items for this run
        $existingRun = PayrollRun::where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->first();

        $adhocEarnings   = 0.0;
        $adhocDeductions = 0.0;
        if ($existingRun) {
            foreach ($existingRun->items ?? [] as $item) {
                if ($item->type === 'earning')    $adhocEarnings   += (float) $item->amount;
                if ($item->type === 'deduction')  $adhocDeductions += (float) $item->amount;
            }
        }

        $gross += $adhocEarnings;

        // Run Kenya statutory engine with org-specific config (falls back to hardcoded defaults if none set)
        $stat = KenyaStatutoryEngine::compute($gross, $insurance, $this->loadPayrollConfig($period->organization_id, $period));

        /*
         * The engine decides what comes off the payslip — NITA is the
         * employer's unless configured otherwise, and adding it here was what
         * made every net pay fifty shillings light.
         */
        $totalDeductions = $stat['employee_deductions'] + $adhocDeductions;

        $netPay = max(0, $gross - $totalDeductions);

        $attributes = [
            // Stored, not recomputed: the P9 used to derive its own and drifted
            // from the PAYE beside it the moment the rules changed.
            'taxable_income'       => $stat['taxable_income'],
            'allowable_deductions' => $stat['allowable_deductions'],
            'basic_salary'      => $basic,
            'house_allowance'   => $house,
            'transport_allowance' => $transport,
            'other_earnings'    => $medical + $other + $adhocEarnings,
            'gross_pay'         => $stat['gross_pay'],
            'paye'              => $stat['paye'],
            'shif'              => $stat['shif'],
            'nssf_tier1'        => $stat['nssf_tier1'],
            'nssf_tier2'        => $stat['nssf_tier2'],
            'housing_levy'      => $stat['housing_levy'],
            'nita'              => $stat['nita'],
            'personal_relief'   => $stat['personal_relief'],
            'insurance_relief'  => $stat['insurance_relief'],
            'paye_payable'      => $stat['paye_payable'],
            'total_deductions'  => round($totalDeductions, 2),
            'net_pay'           => round($netPay, 2),
            'earnings'          => [
                'basic'     => $basic,
                'house'     => $house,
                'transport' => $transport,
                'medical'   => $medical,
                'other'     => $other,
                'adhoc'     => $adhocEarnings,
            ],
            'deductions'        => [
                'paye'          => $stat['paye_payable'],
                'shif'          => $stat['shif'],
                'nssf_tier1'    => $stat['nssf_tier1'],
                'nssf_tier2'    => $stat['nssf_tier2'],
                'housing_levy'  => $stat['housing_levy'],
                'nita'          => $stat['nita'],
                'adhoc'         => $adhocDeductions,
            ],
            'status'            => PayrollRun::STATUS_DRAFT,
        ];

        return PayrollRun::updateOrCreate(
            ['payroll_period_id' => $period->id, 'employee_id' => $employee->id],
            $attributes,
        );
    }

    /**
     * Load org-specific payroll config from DB for the engine.
     * Returns [] if no config is set, so the engine uses its hardcoded Kenya defaults.
     */
    /**
     * The rates in force for the month being run.
     *
     * Both selections used to ignore the period entirely: statutory rates were
     * loaded with `get()->keyBy('param_key')`, which silently keeps whichever
     * row the database returned last once a key has more than one dated
     * version — and having more than one is the entire point of the table.
     * Tax bands were chosen by `now()`, so reprocessing a January period in
     * March taxed it at March's bands.
     *
     * Rates are picked by the period's start date instead. A correction made
     * today therefore applies to the months it was made for and leaves earlier
     * payslips alone, which is the only behaviour that survives an audit.
     */
    private function loadPayrollConfig(int $organizationId, ?PayrollPeriod $period = null): array
    {
        $asOf = $period?->start_date ?? now();
        $config = [];

        $rates = PayrollConfigStatutoryRate::where('organization_id', $organizationId)
            ->whereDate('effective_date', '<=', $asOf)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', $asOf))
            // Latest effective row first, so the first one seen for a key wins.
            ->orderByDesc('effective_date')
            ->get();

        foreach ($rates as $rate) {
            if (! array_key_exists($rate->param_key, $config)) {
                $config[$rate->param_key] = is_numeric($rate->value)
                    ? (float) $rate->value
                    : $rate->value;
            }
        }

        $taxBands = PayrollConfigTaxBand::where('organization_id', $organizationId)
            ->where('tax_year', (int) \Carbon\CarbonImmutable::parse($asOf)->format('Y'))
            ->orderBy('band_order')
            ->get();

        if ($taxBands->isNotEmpty()) {
            $config['tax_bands'] = $taxBands->map(fn ($b) => [
                'lower_limit' => (float) $b->lower_limit,
                'upper_limit' => $b->upper_limit !== null ? (float) $b->upper_limit : null,
                'rate'        => (float) $b->rate,
            ])->toArray();
        }

        return $config;
    }
}
