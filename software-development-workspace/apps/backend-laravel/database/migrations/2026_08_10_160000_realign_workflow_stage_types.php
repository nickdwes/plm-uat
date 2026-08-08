<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The migrations could no longer rebuild production.
 *
 * `workflow_stages.stage_type` was created as an enum of application,
 * screening, interview, decision, offer, hired, rejected. Production's check
 * constraint reads applied, screening, interview, shortlisted, offer, hired,
 * rejected, pre_onboarding — altered directly in the database at some point,
 * without a migration to carry it.
 *
 * Every configured stage now uses the second set: `applied`, `shortlisted` and
 * `pre_onboarding` are all live, and none of the three would have been
 * insertable on a database built from the migrations. So a fresh deploy, a
 * restored backup or any test suite would produce a schema that rejects the
 * configuration the running system depends on — which is exactly what happened
 * the first time a test tried to create a stage.
 *
 * This makes the migration the source of truth again. It is deliberately
 * written to be safe on both: the constraint is dropped if present and recreated
 * from the values actually in use.
 */
return new class extends Migration
{
    /** What the configured pipelines actually use, plus the two never taken up. */
    private const TYPES = [
        'applied', 'screening', 'interview', 'shortlisted',
        'offer', 'hired', 'rejected', 'pre_onboarding',
    ];

    public function up(): void
    {
        $this->replaceCheck(self::TYPES);

        /*
         * Anything left on the old vocabulary is translated rather than
         * refused. `application` and `decision` were the original names for
         * what the live pipelines call `applied` and `shortlisted`; a row
         * holding either would otherwise make the new constraint unaddable.
         */
        DB::table('workflow_stages')->where('stage_type', 'application')->update(['stage_type' => 'applied']);
        DB::table('workflow_stages')->where('stage_type', 'decision')->update(['stage_type' => 'shortlisted']);
    }

    public function down(): void
    {
        DB::table('workflow_stages')->where('stage_type', 'applied')->update(['stage_type' => 'application']);
        DB::table('workflow_stages')->where('stage_type', 'shortlisted')->update(['stage_type' => 'decision']);
        DB::table('workflow_stages')->whereIn('stage_type', ['pre_onboarding'])->update(['stage_type' => 'interview']);

        $this->replaceCheck(['application', 'screening', 'interview', 'decision', 'offer', 'hired', 'rejected']);
    }

    /**
     * Postgres has no ALTER on a check constraint, so it is dropped and
     * rebuilt. Named explicitly because Laravel's enum() names it after the
     * table and column, and guessing wrong would silently leave two.
     */
    private function replaceCheck(array $types): void
    {
        $name = 'workflow_stages_stage_type_check';
        $list = implode(', ', array_map(fn ($t) => "'" . $t . "'", $types));

        DB::statement("ALTER TABLE workflow_stages DROP CONSTRAINT IF EXISTS {$name}");
        DB::statement("ALTER TABLE workflow_stages ADD CONSTRAINT {$name} CHECK (stage_type IN ({$list}))");
    }
};
