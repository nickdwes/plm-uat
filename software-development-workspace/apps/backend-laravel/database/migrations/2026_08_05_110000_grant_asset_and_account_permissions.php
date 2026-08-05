<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives the Assets and Accounts modules someone who can use them.
 *
 * Both shipped with no holder but `group_admin`, whose wildcard hides the
 * problem: the module works for whoever tests it and for nobody else. That is
 * the quieter half of the failure this repository has already seen once in the
 * other direction — a lockout announces itself, an unusable module just looks
 * like nobody has got round to it.
 *
 * Assets is granted to existing roles because who issues a laptop is already
 * settled by how this organisation works. Accounts is not: the customer book
 * is commercially sensitive, no existing role is the obvious holder, and
 * handing it to every department manager or to HR would be a decision about
 * the business rather than about the software. It gets a dedicated role
 * instead, assigned deliberately to the people who should have it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Assets ────────────────────────────────────────────────────────
        //
        // HR issues and recovers equipment as part of onboarding and exit,
        // which the onboarding module already assumes.
        $this->grant('org_hr', [
            'assets.view', 'assets.create', 'assets.update',
            'assets.assign', 'assets.resolve_issue',
        ]);

        // Managers approve their team's requests and need to see what the team
        // holds. They do not maintain the register.
        $this->grant('dept_manager', ['assets.view', 'assets.approve']);

        // Employees see what they hold, ask for what they need, and report
        // faults. Anything less makes the register something done to them.
        $this->grant('employee', ['assets.view_own', 'assets.request', 'assets.report_issue']);

        // ── Accounts ──────────────────────────────────────────────────────
        //
        // A dedicated role rather than an addition to an existing one. Nobody
        // is given the customer book by side effect of this migration; the
        // role exists so it can be assigned on purpose.
        if (! DB::table('roles')->where('name', 'account_manager')->exists()) {
            DB::table('roles')->insert([
                'name'         => 'account_manager',
                'display_name' => 'Account Manager',
                'description'  => 'Owns customer relationships. Sees and maintains their own book of customers.',
                'scope'        => 'organization',
                'is_system'    => true,
                'permissions'  => json_encode([
                    // Their own book, not the whole customer base. Widening
                    // this to accounts.view is a deliberate assignment, not
                    // the default.
                    'accounts.view_own',
                    'accounts.create',
                    'accounts.update',
                ]),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        // Deliberately not granted to anyone here: accounts.delete,
        // accounts.assign and accounts.manage_reference_data. Reassigning
        // customers between managers and editing the sectors every report
        // groups by are administrative acts, and group_admin already holds
        // them through its wildcard.
    }

    public function down(): void
    {
        $this->revoke('org_hr', [
            'assets.view', 'assets.create', 'assets.update',
            'assets.assign', 'assets.resolve_issue',
        ]);
        $this->revoke('dept_manager', ['assets.view', 'assets.approve']);
        $this->revoke('employee', ['assets.view_own', 'assets.request', 'assets.report_issue']);

        DB::table('roles')->where('name', 'account_manager')->where('is_system', true)->delete();
    }

    private function grant(string $role, array $permissions): void
    {
        if ($permissions === []) {
            return;
        }

        $row = DB::table('roles')->where('name', $role)->first();
        if (! $row) {
            return;
        }

        $current = json_decode($row->permissions ?? '[]', true) ?: [];

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
