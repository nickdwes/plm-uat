<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * One employee record per login.
 *
 * `employees.user_id` carried a foreign key and nothing else, while
 * `User::employee()` is a `HasOne` — so two employees pointing at one login
 * would have made "my employee record" a question with two answers, and the
 * relation would have silently picked whichever sorted first. Everything that
 * asks "is this mine" — own-leads, own-tasks, own-leave — hangs off that.
 *
 * Live data is already one-to-one (0 duplicates across 88 linked employees), so
 * this constrains what is true rather than changing anything.
 *
 * Partial, on two counts. Nulls are excluded because most employees have no
 * login and never will. Soft-deleted rows are excluded because a leaver's
 * record keeps its `user_id` for history, and that must not stop the same
 * person being re-hired and linked again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX employees_user_id_unique
             ON employees (user_id)
             WHERE user_id IS NOT NULL AND deleted_at IS NULL',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS employees_user_id_unique');
    }
};
