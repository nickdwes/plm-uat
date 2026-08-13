<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seats the vendor says are in use, kept apart from seats we can name a person for.
 *
 * The register derives seats-in-use from allocations, because a hand-kept
 * number drifts the first time somebody leaves. That is still right — but it
 * means a freshly imported licence reads as nought of fifty-five while
 * Microsoft says fifty-five of fifty-five, and nought is not what is happening.
 *
 * So the vendor's own count lands in its own column and is labelled as theirs.
 * The two are meant to disagree until employees are migrated and the accounts
 * are matched; the gap between them is the finding, not an error to reconcile
 * away. Storing it as `seats_used` would have thrown that away and quietly made
 * the register agree with Microsoft about people it has never heard of.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->unsignedInteger('seats_assigned_reported')->nullable()->after('seats_total');
        });
    }

    public function down(): void
    {
        Schema::table('assets', fn (Blueprint $t) => $t->dropColumn('seats_assigned_reported'));
    }
};
