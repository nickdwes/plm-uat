<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Extend the `goals.level` enum to include Functional Unit, Department,
     * Business Unit, and Practice levels in addition to the existing
     * Individual and Organizational values.
     */
    public function up(): void
    {
        // PostgreSQL — drop old check constraint and add expanded one
        DB::statement("ALTER TABLE goals DROP CONSTRAINT IF EXISTS goals_level_check");
        DB::statement("
            ALTER TABLE goals
            ADD CONSTRAINT goals_level_check
            CHECK (level IN (
                'functional_unit',
                'department',
                'business_unit',
                'practice',
                'individual',
                'organizational'
            ))
        ");
    }

    public function down(): void
    {
        // Revert: rows with new level values would violate the constraint,
        // so we first reset them to 'individual' before shrinking the enum.
        DB::statement("
            UPDATE goals
            SET level = 'individual'
            WHERE level IN ('functional_unit', 'department', 'business_unit', 'practice')
        ");

        DB::statement("ALTER TABLE goals DROP CONSTRAINT IF EXISTS goals_level_check");
        DB::statement("
            ALTER TABLE goals
            ADD CONSTRAINT goals_level_check
            CHECK (level IN ('individual', 'organizational'))
        ");
    }
};
