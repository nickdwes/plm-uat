<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Preserves payroll separation of duties across the move to permissions.
 *
 * PayrollPeriodPolicy previously gated approval on isGlobalHR() — a role-name
 * check — so org_hr could not approve despite holding `payroll.*`. Resolving
 * that policy from permissions instead would have silently granted approval to
 * the very people who prepare the run, because the wildcard already covers it.
 *
 * Expressed as an exclusion rather than by enumerating the payroll permissions
 * org_hr *should* have: an enumeration silently omits anything added later,
 * whereas an exclusion keeps the wildcard current and names exactly what is
 * withheld and why.
 *
 * Withheld from org_hr:
 *   payroll.approve            releasing money is not the preparer's call
 *   payroll.manage_statutory   PAYE bands and rates are set by legislation
 *
 * dept_manager and employee hold no payroll wildcard, so neither is affected.
 * group_admin holds "*" and is unchanged.
 */
return new class extends Migration
{
    private const WITHHELD = ['payroll.approve', 'payroll.manage_statutory'];

    public function up(): void
    {
        $role = DB::table('roles')->where('name', 'org_hr')->first();

        if (! $role) {
            return;
        }

        $excluded = json_decode($role->excluded_permissions ?? '[]', true) ?: [];

        DB::table('roles')->where('id', $role->id)->update([
            'excluded_permissions' => json_encode(
                array_values(array_unique(array_merge($excluded, self::WITHHELD)))
            ),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $role = DB::table('roles')->where('name', 'org_hr')->first();

        if (! $role) {
            return;
        }

        $excluded = json_decode($role->excluded_permissions ?? '[]', true) ?: [];

        DB::table('roles')->where('id', $role->id)->update([
            'excluded_permissions' => json_encode(
                array_values(array_diff($excluded, self::WITHHELD))
            ),
            'updated_at' => now(),
        ]);
    }
};
