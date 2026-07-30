<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grants the performance, goals and OKR permission families, which had no
 * holder at all.
 *
 * Those controllers were previously ungated. Gating them without this would
 * have denied every user including group admins — the inverse failure of the
 * one being fixed, and a worse one, because a lockout is immediate and total
 * while an over-permission is quiet.
 *
 * group_admin becomes the global wildcard. It previously listed
 * resource-specific wildcards, which meant every new resource silently
 * excluded the top role until someone remembered to extend the list. That is
 * a recurring outage waiting on a new module, and there are three modules
 * queued. A group administrator able to do everything is also the honest
 * description of the role.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->grant('group_admin', ['*'], replace: true);

        // HR runs performance cycles, goal setting and OKRs.
        $this->grant('org_hr', ['performance.*', 'goals.*', 'okr.*']);

        // Managers see their team's performance and work with team goals,
        // but do not define OKR cycles or run reviews.
        $this->grant('dept_manager', [
            'performance.view_team',
            'goals.view', 'goals.create', 'goals.update',
            'okr.view',
        ]);

        // Employees see their own, and read the OKRs they are measured against.
        $this->grant('employee', [
            'performance.view_own',
            'goals.view_own',
            'okr.view',
        ]);
    }

    public function down(): void
    {
        $this->revoke('org_hr', ['performance.*', 'goals.*', 'okr.*']);
        $this->revoke('dept_manager', [
            'performance.view_team', 'goals.view', 'goals.create', 'goals.update', 'okr.view',
        ]);
        $this->revoke('employee', ['performance.view_own', 'goals.view_own', 'okr.view']);

        $this->grant('group_admin', [
            'organizations.*', 'employees.*', 'departments.*', 'payroll.*',
            'leave.*', 'reports.*', 'settings.*', 'audit.*',
        ], replace: true);
    }

    private function grant(string $role, array $permissions, bool $replace = false): void
    {
        $row = DB::table('roles')->where('name', $role)->first();

        if (! $row) {
            return;
        }

        $current = $replace ? [] : (json_decode($row->permissions ?? '[]', true) ?: []);

        DB::table('roles')->where('id', $row->id)->update([
            'permissions' => json_encode(array_values(array_unique(array_merge($current, $permissions)))),
            'updated_at'  => now(),
        ]);
    }

    private function revoke(string $role, array $permissions): void
    {
        $row = DB::table('roles')->where('name', $role)->first();

        if (! $row) {
            return;
        }

        $current = json_decode($row->permissions ?? '[]', true) ?: [];

        DB::table('roles')->where('id', $row->id)->update([
            'permissions' => json_encode(array_values(array_diff($current, $permissions))),
            'updated_at'  => now(),
        ]);
    }
};
