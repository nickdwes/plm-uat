<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollPeriod;
use App\Models\PayrollConfigStatutoryRate;
use App\Models\PayrollConfigTaxBand;
use App\Models\PayrollDeductionCode;
use App\Models\PayrollEarningCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayrollConfigController extends Controller
{
    private function orgId(): int
    {
        return (int) app('current.organization_id');
    }

    // ── GET /api/v1/payroll-config ────────────────────────────────────────────
    // Returns full config for the current org. If no config exists, returns
    // the built-in Kenya 2025/2026 defaults so the UI can pre-populate.

    public function index(): JsonResponse
    {
        // Reading the payroll configuration screen.
        $this->authorize('viewConfig', PayrollPeriod::class);

        $orgId = $this->orgId();
        $year  = (int) now()->format('Y');

        $taxBands = PayrollConfigTaxBand::where('organization_id', $orgId)
            ->where('tax_year', $year)
            ->orderBy('band_order')
            ->get();

        if ($taxBands->isEmpty()) {
            $taxBands = collect($this->defaultTaxBands($year));
        }

        $rateRows = PayrollConfigStatutoryRate::where('organization_id', $orgId)
            ->orderBy('param_key')
            ->get()
            ->keyBy('param_key');

        // Build rate map — DB values override defaults
        $rates = [];
        foreach (PayrollConfigStatutoryRate::PARAM_KEYS as $key) {
            $rates[$key] = [
                'param_key'      => $key,
                'value'          => isset($rateRows[$key]) ? (float) $rateRows[$key]->value : PayrollConfigStatutoryRate::DEFAULTS[$key],
                'description'    => PayrollConfigStatutoryRate::DESCRIPTIONS[$key],
                'effective_date' => isset($rateRows[$key]) ? $rateRows[$key]->effective_date?->toDateString() : null,
                'from_db'        => isset($rateRows[$key]),
            ];
        }

        $earningCodes = PayrollEarningCode::where('organization_id', $orgId)
            ->orderBy('code')
            ->get();

        $deductionCodes = PayrollDeductionCode::where('organization_id', $orgId)
            ->orderBy('priority')
            ->get();

        return response()->json([
            'tax_year'       => $year,
            'tax_bands'      => $taxBands->values(),
            'statutory_rates'=> $rates,
            'earning_codes'  => $earningCodes,
            'deduction_codes'=> $deductionCodes,
        ]);
    }

    // ── PUT /api/v1/payroll-config/tax-bands ─────────────────────────────────
    // Bulk-replace tax bands for the given tax year.

    public function updateTaxBands(Request $request): JsonResponse
    {
        // Replaces a whole tax year of PAYE bands.
        $this->authorize('manageStatutory', PayrollPeriod::class);

        $orgId = $this->orgId();

        $data = $request->validate([
            'tax_year'              => 'required|integer|min:2020|max:2100',
            'effective_date'        => 'required|date',
            'bands'                 => 'required|array|min:1',
            'bands.*.band_order'    => 'required|integer|min:1',
            'bands.*.lower_limit'   => 'required|numeric|min:0',
            'bands.*.upper_limit'   => 'nullable|numeric|gt:bands.*.lower_limit',
            'bands.*.rate'          => 'required|numeric|min:0|max:100',
        ]);

        $year = $data['tax_year'];

        // Delete existing bands for this year and org, then re-insert
        PayrollConfigTaxBand::where('organization_id', $orgId)
            ->where('tax_year', $year)
            ->delete();

        $inserted = [];
        foreach ($data['bands'] as $band) {
            $inserted[] = PayrollConfigTaxBand::create([
                'organization_id' => $orgId,
                'tax_year'        => $year,
                'band_order'      => $band['band_order'],
                'lower_limit'     => $band['lower_limit'],
                'upper_limit'     => $band['upper_limit'] ?? null,
                'rate'            => $band['rate'],
                'effective_date'  => $data['effective_date'],
            ]);
        }

        return response()->json(['data' => $inserted]);
    }

    // ── PUT /api/v1/payroll-config/statutory-rates ───────────────────────────
    // Upsert all statutory rate parameters for the current org.

    public function updateStatutoryRates(Request $request): JsonResponse
    {
        // Changes the rates every payslip is calculated from.
        $this->authorize('manageStatutory', PayrollPeriod::class);

        $orgId = $this->orgId();

        $data = $request->validate([
            'effective_date'       => 'required|date',
            'rates'                => 'required|array',
            'rates.*.param_key'    => ['required', Rule::in(PayrollConfigStatutoryRate::PARAM_KEYS)],
            'rates.*.value'        => 'required|numeric|min:0',
        ]);

        $upserted = [];
        foreach ($data['rates'] as $rate) {
            $upserted[] = PayrollConfigStatutoryRate::updateOrCreate(
                [
                    'organization_id' => $orgId,
                    'param_key'       => $rate['param_key'],
                    'effective_date'  => $data['effective_date'],
                ],
                [
                    'value'       => $rate['value'],
                    'description' => PayrollConfigStatutoryRate::DESCRIPTIONS[$rate['param_key']] ?? null,
                ]
            );
        }

        return response()->json(['data' => $upserted]);
    }

    // ── POST /api/v1/payroll-config/earning-codes ─────────────────────────────

    public function storeEarningCode(Request $request): JsonResponse
    {
        $this->authorize('manageConfig', PayrollPeriod::class);

        $orgId = $this->orgId();

        $data = $request->validate([
            'code'                => ['required', 'string', 'max:30', Rule::unique('payroll_earning_codes')->where('organization_id', $orgId)],
            'name'                => 'required|string|max:120',
            'is_taxable'          => 'boolean',
            'is_pensionable'      => 'boolean',
            'is_shif_applicable'  => 'boolean',
            'is_nssf_applicable'  => 'boolean',
            'is_active'           => 'boolean',
            'effective_date'      => 'nullable|date',
        ]);

        $code = PayrollEarningCode::create(array_merge($data, ['organization_id' => $orgId]));
        return response()->json($code, 201);
    }

    // ── PUT /api/v1/payroll-config/earning-codes/{id} ─────────────────────────

    public function updateEarningCode(Request $request, PayrollEarningCode $earningCode): JsonResponse
    {
        $this->authorize('manageConfig', PayrollPeriod::class);

        abort_unless($earningCode->organization_id === $this->orgId(), 403);

        $data = $request->validate([
            'name'                => 'sometimes|string|max:120',
            'is_taxable'          => 'boolean',
            'is_pensionable'      => 'boolean',
            'is_shif_applicable'  => 'boolean',
            'is_nssf_applicable'  => 'boolean',
            'is_active'           => 'boolean',
            'effective_date'      => 'nullable|date',
        ]);

        $earningCode->update($data);
        return response()->json($earningCode);
    }

    // ── DELETE /api/v1/payroll-config/earning-codes/{id} ─────────────────────

    public function destroyEarningCode(PayrollEarningCode $earningCode): JsonResponse
    {
        $this->authorize('manageConfig', PayrollPeriod::class);

        abort_unless($earningCode->organization_id === $this->orgId(), 403);
        $earningCode->delete();
        return response()->json(null, 204);
    }

    // ── POST /api/v1/payroll-config/deduction-codes ───────────────────────────

    public function storeDeductionCode(Request $request): JsonResponse
    {
        $this->authorize('manageConfig', PayrollPeriod::class);

        $orgId = $this->orgId();

        $data = $request->validate([
            'code'             => ['required', 'string', 'max:30', Rule::unique('payroll_deduction_codes')->where('organization_id', $orgId)],
            'name'             => 'required|string|max:120',
            'deduction_type'   => ['required', Rule::in(['statutory', 'checkoff', 'loan'])],
            'priority'         => 'integer|min:1|max:999',
            'calculation_type' => ['required', Rule::in(['fixed', 'percentage'])],
            'max_amount'       => 'nullable|numeric|min:0',
            'recover_balance'  => 'boolean',
            'is_active'        => 'boolean',
        ]);

        $code = PayrollDeductionCode::create(array_merge($data, ['organization_id' => $orgId]));
        return response()->json($code, 201);
    }

    // ── PUT /api/v1/payroll-config/deduction-codes/{id} ───────────────────────

    public function updateDeductionCode(Request $request, PayrollDeductionCode $deductionCode): JsonResponse
    {
        $this->authorize('manageConfig', PayrollPeriod::class);

        abort_unless($deductionCode->organization_id === $this->orgId(), 403);

        $data = $request->validate([
            'name'             => 'sometimes|string|max:120',
            'deduction_type'   => [Rule::in(['statutory', 'checkoff', 'loan'])],
            'priority'         => 'integer|min:1|max:999',
            'calculation_type' => [Rule::in(['fixed', 'percentage'])],
            'max_amount'       => 'nullable|numeric|min:0',
            'recover_balance'  => 'boolean',
            'is_active'        => 'boolean',
        ]);

        $deductionCode->update($data);
        return response()->json($deductionCode);
    }

    // ── DELETE /api/v1/payroll-config/deduction-codes/{id} ───────────────────

    public function destroyDeductionCode(PayrollDeductionCode $deductionCode): JsonResponse
    {
        $this->authorize('manageConfig', PayrollPeriod::class);

        abort_unless($deductionCode->organization_id === $this->orgId(), 403);
        $deductionCode->delete();
        return response()->json(null, 204);
    }

    // ── POST /api/v1/payroll-config/seed-defaults ─────────────────────────────
    // Seeds Kenya 2025/2026 defaults for the current org if not already set.

    public function seedDefaults(Request $request): JsonResponse
    {
        // Rewrites statutory configuration wholesale.
        $this->authorize('manageStatutory', PayrollPeriod::class);

        $orgId = $this->orgId();
        $year  = (int) now()->format('Y');
        $effectiveDate = $year . '-01-01';

        // Tax bands
        if (!PayrollConfigTaxBand::where('organization_id', $orgId)->where('tax_year', $year)->exists()) {
            foreach ($this->defaultTaxBands($year) as $band) {
                PayrollConfigTaxBand::create(array_merge($band, [
                    'organization_id' => $orgId,
                    'effective_date'  => $effectiveDate,
                ]));
            }
        }

        // Statutory rates
        foreach (PayrollConfigStatutoryRate::DEFAULTS as $key => $value) {
            PayrollConfigStatutoryRate::firstOrCreate(
                ['organization_id' => $orgId, 'param_key' => $key, 'effective_date' => $effectiveDate],
                ['value' => $value, 'description' => PayrollConfigStatutoryRate::DESCRIPTIONS[$key] ?? null]
            );
        }

        // Earning codes
        foreach (PayrollEarningCode::DEFAULTS as $ec) {
            PayrollEarningCode::firstOrCreate(
                ['organization_id' => $orgId, 'code' => $ec['code']],
                array_merge($ec, ['is_active' => true, 'effective_date' => $effectiveDate])
            );
        }

        // Deduction codes
        foreach (PayrollDeductionCode::DEFAULTS as $dc) {
            PayrollDeductionCode::firstOrCreate(
                ['organization_id' => $orgId, 'code' => $dc['code']],
                array_merge($dc, ['is_active' => true])
            );
        }

        return response()->json(['message' => 'Kenya ' . $year . ' defaults seeded successfully.']);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function defaultTaxBands(int $year): array
    {
        return [
            ['tax_year' => $year, 'band_order' => 1, 'lower_limit' => 0,       'upper_limit' => 24000,  'rate' => 10.00],
            ['tax_year' => $year, 'band_order' => 2, 'lower_limit' => 24001,   'upper_limit' => 32333,  'rate' => 25.00],
            ['tax_year' => $year, 'band_order' => 3, 'lower_limit' => 32334,   'upper_limit' => 500000, 'rate' => 30.00],
            ['tax_year' => $year, 'band_order' => 4, 'lower_limit' => 500001,  'upper_limit' => 800000, 'rate' => 32.50],
            ['tax_year' => $year, 'band_order' => 5, 'lower_limit' => 800001,  'upper_limit' => null,   'rate' => 35.00],
        ];
    }
}
