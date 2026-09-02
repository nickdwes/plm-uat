<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give the live roles the roster permissions the code now checks.
 *
 * The roster controllers used to ask `isOrgHR()` / `isManagerIn()` — role
 * *names* — so no permission existed to grant. Now that they ask for
 * `roster.view` / `roster.manage` / `roster.publish`, the roles whose names
 * used to satisfy those checks must hold the equivalent permissions, or this
 * deploy silently takes the rota away from everyone who runs it.
 *
 * A migration rather than the seeder alone, because the live `roles` table has
 * diverged from the seeder (11 rows vs 6 seeded) and re-running a seeder in
 * production is not part of the deploy. Idempotent: appends only what is
 * missing.
 */
return new class extends Migration
{
    private const GRANTS = [
        'org_hr'       => ['roster.*', 'benefits.*'],
        'dept_manager' => ['roster.view', 'roster.manage'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $role => $additions) {
            $this->append($role, $additions);
        }
    }

    public function down(): void
    {
        foreach (self::GRANTS as $role => $additions) {
            $row = DB::table('roles')->where('name', $role)->first();

            if (! $row) {
                continue;
            }

            $permissions = array_values(array_diff(
                json_decode($row->permissions ?? '[]', true) ?: [],
                $additions,
            ));

            DB::table('roles')->where('id', $row->id)->update([
                'permissions' => json_encode($permissions),
            ]);
        }
    }

    private function append(string $role, array $additions): void
    {
        $row = DB::table('roles')->where('name', $role)->first();

        if (! $row) {
            return;
        }

        $permissions = json_decode($row->permissions ?? '[]', true) ?: [];

        foreach ($additions as $permission) {
            if (! in_array($permission, $permissions, true)) {
                $permissions[] = $permission;
            }
        }

        DB::table('roles')->where('id', $row->id)->update([
            'permissions' => json_encode($permissions),
        ]);
    }
};
