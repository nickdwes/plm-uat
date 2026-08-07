<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The day is what you committed to, not where the hours went.
 *
 * I built the previous step as an hour grid because the designs showed one, and
 * that put the emphasis in the wrong place. The question is not what somebody
 * was doing at half past ten. It is what they said they would finish today,
 * whether they finished it, and — when they did not — whether anybody was told.
 *
 * That is a different record, so it gets a different name. `attendance_blocks`
 * described a slot on a clock. A commitment describes a promise and its
 * outcome, and the outcome is the part that was missing.
 *
 * What follows from the change:
 *
 *   Times become optional. Somebody who wants to note that the client call is
 *   at eleven still can, but nothing requires it and nothing is built on it. An
 *   hour grid you must fill in is a timesheet by another name, and people fill
 *   those in at the end of the week from memory.
 *
 *   An unfinished commitment must carry a reason. This is the whole point —
 *   "effective communication if not completed" is not a report somebody runs
 *   monthly, it is a sentence written on the day by the person who knows why.
 *   So the reason is required at the moment the outcome is recorded, not
 *   requested afterwards when the answer has been forgotten.
 *
 *   Unfinished and unexplained is the number worth watching. Not "how many
 *   tasks slipped" — work slips, and a manager who reacts to that is reacting
 *   to noise. What matters is work that slipped with nobody told, and until now
 *   there was no way to count it.
 *
 * Nothing is lost in the rename: the table is a day old and holds no rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('attendance_blocks', 'attendance_commitments');

        Schema::table('attendance_commitments', function (Blueprint $table) {
            /*
             * Optional now. A commitment is a thing you said you would finish
             * today, not a slot on a clock — and most of them have no hour
             * attached because that is not how the work is decided.
             */
            $table->time('starts_at')->nullable()->change();
            $table->time('ends_at')->nullable()->change();

            // The order somebody means to take them in, which is the only
            // sequencing most days need.
            $table->unsignedSmallInteger('position')->default(0)->after('kind');

            /*
             * How it went: null while the day is still running, then done or
             * not_done. Null is not a third outcome — it is the absence of one,
             * and a day full of nulls after the fact is itself the finding.
             */
            $table->string('outcome', 16)->nullable()->after('position');

            // Required when the outcome is not_done. Enforced by the service
            // rather than the column, so the message can say why.
            $table->text('reason')->nullable()->after('outcome');

            $table->timestamp('reported_at')->nullable()->after('reason');
            $table->foreignId('reported_by')->nullable()->after('reported_at')
                ->constrained('employees')->nullOnDelete();

            /*
             * Where it went instead.
             *
             * An unfinished commitment moved to another day points at its
             * replacement, so "this has slipped four times" is answerable. A
             * boolean would only ever say it moved once.
             */
            $table->foreignId('carried_to_id')->nullable()->after('reported_by')
                ->constrained('attendance_commitments')->nullOnDelete();

            $table->index(['attendance_day_id', 'position']);
            // The question a manager actually asks: what slipped without being
            // explained, across everybody.
            $table->index(['organization_id', 'outcome']);
        });

        // The old index named for a timeline no longer describes anything.
        DB::statement('DROP INDEX IF EXISTS attendance_blocks_attendance_day_id_starts_at_index');
    }

    public function down(): void
    {
        Schema::table('attendance_commitments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reported_by');
            $table->dropConstrainedForeignId('carried_to_id');
            $table->dropIndex(['attendance_day_id', 'position']);
            $table->dropIndex(['organization_id', 'outcome']);
            $table->dropColumn(['position', 'outcome', 'reason', 'reported_at']);
        });

        Schema::rename('attendance_commitments', 'attendance_blocks');
    }
};
