<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Organisation update and logo upload were gated on role NAMES
 * (isGroupAdmin/hasRole('org_hr')) — unreachable by any custom role, the
 * increment-1 disease. The check moved onto `organizations.manage`; this
 * back-fills that permission into live `org_hr` so HR keeps exactly the
 * access the role-name check gave it. Group admins hold `*` already.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = DB::table('roles')->where('name', 'org_hr')->first();
        if (! $role) {
            return;
        }

        $permissions = json_decode($role->permissions ?? '[]', true) ?: [];
        if (in_array('organizations.manage', $permissions, true)) {
            return;
        }

        $permissions[] = 'organizations.manage';
        DB::table('roles')->where('id', $role->id)->update([
            'permissions' => json_encode(array_values($permissions)),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        $role = DB::table('roles')->where('name', 'org_hr')->first();
        if (! $role) {
            return;
        }

        $permissions = array_values(array_diff(
            json_decode($role->permissions ?? '[]', true) ?: [],
            ['organizations.manage'],
        ));
        DB::table('roles')->where('id', $role->id)->update([
            'permissions' => json_encode($permissions),
            'updated_at'  => now(),
        ]);
    }
};
