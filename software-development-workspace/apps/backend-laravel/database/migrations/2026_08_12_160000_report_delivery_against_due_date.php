<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting delivery against the date it was due, instead of hours against a
 * clock.
 *
 * Three things the daily report needs and did not have.
 *
 * ── Whether finishing your part finishes the task ──
 *
 * A commitment could only be `done` or `not_done`, which answers "did you do
 * what you said" but not "is the work finished". Those are different days for
 * most tasks: somebody spends Tuesday on a thing and it continues on Wednesday.
 * Without the distinction, a task worked on every day for a fortnight looks
 * identical to one finished on the first afternoon, and neither the person nor
 * their manager can see which is which.
 *
 * ── When the person said it was done ──
 *
 * `completed_at` is stamped when a task reaches done. But a task that requires
 * approval goes to `awaiting_approval` first, and that branch never stamps
 * anything — so `completed_at` ends up holding the date the *approver* acted.
 * The doer's own declaration was not recorded anywhere, which makes "delivered
 * on time" unanswerable for exactly the tasks that matter most.
 *
 * Both dates are kept, deliberately. If the two drift apart it is a fact about
 * how long approvals take, and hiding it inside one column would blame the
 * person who finished on time for the week their manager took to look.
 *
 * ── Risk is not issue ──
 *
 * An overdue task usually surfaces a risk to some later date rather than a
 * problem with today, and the two are acted on differently: an issue wants
 * resolving, a risk wants watching. One column, because the alternative is
 * people writing "RISK:" at the front of a title.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_commitments', function (Blueprint $table) {
            /*
             * Only meaningful alongside `outcome = done`: closed means the work
             * is finished, ongoing means it continues tomorrow. Null where the
             * question does not arise — nothing was done, or nothing has been
             * reported yet.
             */
            $table->string('disposition', 16)->nullable()->after('outcome');
        });

        Schema::table('tasks', function (Blueprint $table) {
            // The day the person doing the work said it was finished, whether
            // or not anybody has accepted it yet.
            $table->timestamp('reported_done_at')->nullable()->after('completed_at');
            /*
             * An employee, beside `completed_by` and `started_by` — the people
             * who did the work. Not `approved_by`, which points at users
             * because approving is an act of an account rather than of a job.
             */
            $table->foreignId('reported_done_by')->nullable()->after('reported_done_at')
                ->constrained('employees')->nullOnDelete();
        });

        Schema::table('task_issues', function (Blueprint $table) {
            $table->string('kind', 16)->default('issue')->after('task_id');
        });

        /*
         * Tasks already finished keep their existing date as the declaration.
         *
         * Not strictly true where approval was involved — the approver's date
         * is the only one that survives — but leaving it null would report
         * every historical task as never delivered, which is a worse lie than
         * an approximate date.
         */
        DB::statement('UPDATE tasks SET reported_done_at = completed_at WHERE completed_at IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('task_issues', fn (Blueprint $t) => $t->dropColumn('kind'));

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reported_done_by');
            $table->dropColumn('reported_done_at');
        });

        Schema::table('attendance_commitments', fn (Blueprint $t) => $t->dropColumn('disposition'));
    }
};
