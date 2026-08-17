<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The NSSF limits in force, and who pays the training levy.
 *
 * ── New rows, not edits ──
 *
 * The rates table is effective-dated and the loader now honours it, so a
 * correction is a new row rather than a change to an old one. Editing the
 * seeded values would silently restate what every past period was run under —
 * and a payslip somebody has already been paid against, and a return already
 * filed with KRA, is not a number to quietly rewrite.
 *
 * ── What is wrong with the seeded values ──
 *
 * NSSF was seeded at 7,000 and 36,000. Those are the year-two limits; from
 * February 2025 the lower is 8,000 and the upper 72,000. At 200,000 gross the
 * old figures deduct 2,160 where 4,320 is due — half the pension the employee
 * is entitled to be building, and a taxable-pay base that is wrong with it.
 *
 * ── NITA ──
 *
 * The industrial training levy is the employer's, KES 50 per employee per
 * month. It was being taken off the employee. The key exists so a business
 * already doing something else can say so, rather than having somebody's
 * take-home change without anyone deciding it should.
 */
return new class extends Migration
{
    /** From February 2025, and the first period this can affect is the next one run. */
    private const EFFECTIVE = '2025-02-01';

    /**
     * The values that were never right, wherever they are dated.
     *
     * The seeded rows claim 7,000 and 36,000 as effective from January 2026 —
     * limits that had not applied for eleven months by then. Because the loader
     * takes the latest effective row, those beat the correction below and the
     * fix did nothing until they were put right.
     *
     * Corrected in place rather than superseded: this is not restating a rate
     * that was once in force, it is fixing one that was wrong when it was
     * written.
     */
    private const CORRECTIONS = [
        ['nssf_tier1_ceiling', 7000, 8000],
        ['nssf_tier2_ceiling', 36000, 72000],
    ];

    public function up(): void
    {
        foreach (self::CORRECTIONS as [$key, $wrong, $right]) {
            DB::table('payroll_config_statutory_rates')
                ->where('param_key', $key)
                ->where('value', $wrong)
                ->whereDate('effective_date', '>', self::EFFECTIVE)
                ->update(['value' => $right, 'updated_at' => now()]);
        }

        $rows = [
            ['nssf_tier1_ceiling', 8000,  'NSSF Tier I upper limit (KES) — NSSF Act 2013, year three'],
            ['nssf_tier2_ceiling', 72000, 'NSSF Tier II upper limit (KES) — NSSF Act 2013, year three'],
        ];

        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
            foreach ($rows as [$key, $value, $description]) {
                $exists = DB::table('payroll_config_statutory_rates')
                    ->where('organization_id', $organizationId)
                    ->where('param_key', $key)
                    ->whereDate('effective_date', self::EFFECTIVE)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('payroll_config_statutory_rates')->insert([
                    'organization_id' => $organizationId,
                    'param_key'       => $key,
                    'value'           => $value,
                    'description'     => $description,
                    'effective_date'  => self::EFFECTIVE,
                    'expires_at'      => null,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('payroll_config_statutory_rates')
            ->whereIn('param_key', ['nssf_tier1_ceiling', 'nssf_tier2_ceiling'])
            ->whereDate('effective_date', self::EFFECTIVE)
            ->delete();
    }
};
