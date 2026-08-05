<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Who can do what with travel.
 *
 *   Everyone           raises requests and follows their own
 *   Line managers      see and approve their team's
 *   Finance            sees everything, and assesses
 *   Executive (CFO)    sees everything, and releases
 *
 * Finance and executive are separate roles rather than one finance role with
 * both permissions. They are the two halves of the control the four-stage
 * chain exists to enforce: the officer who assesses a claim must not also be
 * the person who approves paying it. Combining them in one role would leave
 * the chain intact on paper and defeated in practice.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Anyone can raise a request and follow it. Travel is not a privilege
        // to be granted — an employee who cannot raise one simply travels and
        // claims afterwards, which is the outcome the process exists to avoid.
        $this->grant('employee', ['travel.submit', 'travel.view_own']);

        // Line managers see their team's requests and decide the ones routed
        // to them. `travel.approve` alone is not enough to approve anybody's
        // travel: the policy also requires that the request came from their
        // department, or that they are a named approver for it.
        $this->grant('dept_manager', ['travel.view_team', 'travel.approve', 'travel.view_own', 'travel.submit']);

        // HR does not sit in the travel chain, but raises and follows like
        // everyone else.
        $this->grant('org_hr', ['travel.submit', 'travel.view_own']);

        $this->role('finance_officer', 'Finance Officer',
            'Assesses travel claims. Full visibility of every request in the organisation.', [
                // Full visibility is the point: assessing a claim means seeing
                // it against everything else being spent.
                'travel.view',
                'travel.assess_finance',
                // The rate card is finance's instrument, so they maintain it.
                'travel.manage_rates',
                'travel.submit', 'travel.view_own',
            ]);

        $this->role('finance_executive', 'Finance Executive (CFO)',
            'Final approval before disbursement. Full visibility of every request.', [
                'travel.view',
                'travel.approve_executive',
                'travel.submit', 'travel.view_own',
            ]);
    }

    public function down(): void
    {
        $this->revoke('employee', ['travel.submit', 'travel.view_own']);
        $this->revoke('dept_manager', ['travel.view_team', 'travel.approve', 'travel.view_own', 'travel.submit']);
        $this->revoke('org_hr', ['travel.submit', 'travel.view_own']);

        DB::table('roles')->whereIn('name', ['finance_officer', 'finance_executive'])
            ->where('is_system', true)->delete();
    }

    private function role(string $name, string $display, string $description, array $permissions): void
    {
        if (DB::table('roles')->where('name', $name)->exists()) {
            return;
        }

        DB::table('roles')->insert([
            'name'         => $name,
            'display_name' => $display,
            'description'  => $description,
            'scope'        => 'organization',
            'is_system'    => true,
            'permissions'  => json_encode($permissions),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function grant(string $role, array $permissions): void
    {
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
