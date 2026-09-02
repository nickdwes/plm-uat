<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The six original seeded roles predate the `is_system` flag mattering, so
 * they sat unflagged while the roles added later were flagged at birth. Now
 * that the flag is what makes a built-in an immutable template in the role
 * editor, the originals must carry it too — otherwise the seeder's own roles
 * are editable and a seeder upgrade fights local edits.
 *
 * Idempotent, like every live back-fill in this repo.
 */
return new class extends Migration
{
    private const BUILT_INS = [
        'group_admin',
        'org_hr',
        'dept_manager',
        'employee',
        'service_desk_analyst',
        'support_engineer',
    ];

    public function up(): void
    {
        DB::table('roles')
            ->whereIn('name', self::BUILT_INS)
            ->update(['is_system' => true]);
    }

    public function down(): void
    {
        DB::table('roles')
            ->whereIn('name', self::BUILT_INS)
            ->update(['is_system' => false]);
    }
};
