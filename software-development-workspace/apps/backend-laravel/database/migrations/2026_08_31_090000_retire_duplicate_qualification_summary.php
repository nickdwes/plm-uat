<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the qualification summary, which asked the same five questions twice.
 *
 * The screen carried a graded dimension and a categorical judgement for each of
 * the same things: Budget Alignment above Budget availability, Timeline Fit
 * above Timeline, Stakeholder Access above both Stakeholder engagement and
 * Decision authority access. They were independent columns with nothing
 * reconciling them, so a lead could hold fit_budget = 90 and
 * budget_availability = 'none' and the page would render both without
 * complaint. The duplication is an artefact of transcribing a design mock whose
 * two cards were decorative and shared no data model.
 *
 * Now that each dimension is itself a named answer, the categorical half is the
 * half that survives, folded into the dimension it was shadowing.
 *
 * Dropping rather than keeping, because every one of these columns is empty:
 * 0 of 160 leads and 0 of 130 opportunities carry a value in any of them. There
 * is nothing to migrate, and a nullable column nobody writes is a question the
 * next reader has to answer twice.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'budget_availability',
        'stakeholder_engagement',
        'timeline_estimate',
        'technical_readiness',
        'decision_authority_access',
    ];

    private const TABLES = ['leads', 'opportunities'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $present = array_values(array_filter(
                self::COLUMNS,
                fn (string $column) => Schema::hasColumn($table, $column),
            ));

            if ($present === []) {
                continue;
            }

            Schema::table($table, fn (Blueprint $t) => $t->dropColumn($present));
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'budget_availability')) {
                    $t->string('budget_availability', 24)->nullable();
                }
                if (! Schema::hasColumn($table, 'stakeholder_engagement')) {
                    $t->string('stakeholder_engagement', 24)->nullable();
                }
                if (! Schema::hasColumn($table, 'timeline_estimate')) {
                    $t->string('timeline_estimate')->nullable();
                }
                if (! Schema::hasColumn($table, 'technical_readiness')) {
                    $t->string('technical_readiness', 24)->nullable();
                }
                if (! Schema::hasColumn($table, 'decision_authority_access')) {
                    $t->string('decision_authority_access', 24)->nullable();
                }
            });
        }
    }
};
