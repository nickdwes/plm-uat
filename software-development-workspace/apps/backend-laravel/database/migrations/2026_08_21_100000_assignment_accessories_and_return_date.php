<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What went out with the laptop, and when it is due back.
 *
 * ── Accessories ──
 *
 * The designs put charger, mouse and laptop bag on the assignment form, and
 * then again on the return form as "Accessories returned" with the boxes
 * pre-ticked. That second screen only works if the first one wrote something
 * down: without it, "did the charger come back" is a question nobody can answer
 * and the cost lands on whoever asks last.
 *
 * A list rather than three booleans, because the set is the design's and not
 * the world's — a vehicle goes out with keys and a logbook. It belongs in
 * category configuration eventually; a JSON column gets there without a
 * migration for every new kind of thing.
 *
 * ── Expected return ──
 *
 * The form defaults it to "Employment End", which is the honest answer for a
 * laptop issued to a permanent employee and the wrong one for a loan machine
 * going out for a fortnight. Null means "no date agreed", which is different
 * from "due at the end of employment" and should not be written as though it
 * were the same thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->date('expected_return_date')->nullable()->after('assigned_date');
            $table->jsonb('assigned_accessories')->nullable()->after('expected_return_date');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['expected_return_date', 'assigned_accessories']);
        });
    }
};
