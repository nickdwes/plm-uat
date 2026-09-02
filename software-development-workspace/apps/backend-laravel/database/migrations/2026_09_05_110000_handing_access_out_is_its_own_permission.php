<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `users.provision` — creating an Office 365 account, and creating an
 * employee profile from one that already exists there.
 *
 * Back-filled to whoever already holds `users.deprovision`, because those are
 * the people administering identity today and splitting the permission
 * without this would take the new buttons away from all of them on deploy.
 * Wildcard holders are skipped: `*` and `users.*` already cover it, and
 * adding the concrete string would make the role read as though somebody
 * granted it by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode($role->permissions ?? '[]', true) ?: [];

            if (in_array('*', $permissions, true) || in_array('users.*', $permissions, true)) {
                continue;
            }

            if (! in_array('users.deprovision', $permissions, true)
                || in_array('users.provision', $permissions, true)) {
                continue;
            }

            $permissions[] = 'users.provision';

            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values($permissions)),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode($role->permissions ?? '[]', true) ?: [];

            if (! in_array('users.provision', $permissions, true)) {
                continue;
            }

            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values(array_diff($permissions, ['users.provision']))),
                'updated_at'  => now(),
            ]);
        }
    }
};
