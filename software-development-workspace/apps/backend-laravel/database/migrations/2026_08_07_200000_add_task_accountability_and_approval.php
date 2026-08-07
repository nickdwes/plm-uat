<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who started it, who finished it, and who was asked to sign it off.
 *
 * Three holes, all in the same place — what actually happened to a task.
 *
 *   The legacy filled `started_by` on 96% of its 9,055 tasks and `completed_by`
 *   on 91%. I recorded who approved and not who did the work. With assist and
 *   collaborate putting several people on one task, the owner and the person
 *   who actually finished it are different questions, and only one of them was
 *   answerable.
 *
 *   Approval had columns and no route. A task could reach Awaiting Approval —
 *   a status, and a column on the board — and never leave, because nothing
 *   could approve it. 24% of legacy tasks were approved, so this was a live
 *   path left half-built, which is worse than not building it: the column
 *   implies the button exists.
 *
 *   And nobody was ever told. Naming the approver on the task is what makes it
 *   possible to notify them, rather than hoping somebody notices a status.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Notifications could not point at a task.
         *
         * `notifiable_id` was written as a uuid, and every id in this system
         * outside the notifications table itself is an integer — so the
         * polymorphic link could never have been written without failing. A
         * string holds both, and this is the first code to actually use it.
         */
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('notifiable_id')->nullable()->change();
        });

        Schema::table('tasks', function (Blueprint $table) {
            // Stamped when the status first leaves todo, so it records what
            // happened rather than what somebody typed afterwards.
            $table->foreignId('started_by')->nullable()->after('started_on')
                ->constrained('employees')->nullOnDelete();

            $table->foreignId('completed_by')->nullable()->after('completed_at')
                ->constrained('employees')->nullOnDelete();

            /*
             * Who has to sign it off.
             *
             * `requires_approval` alone said a signature was needed without
             * saying whose, which leaves the task waiting on nobody in
             * particular — the same shape as the legacy's 261 delegations that
             * were never answered.
             */
            $table->foreignId('approver_id')->nullable()->after('requires_approval')
                ->constrained('employees')->nullOnDelete();

            // Why it was sent back. A rejection with no reason just puts the
            // task back where it was with nothing learned.
            $table->text('approval_note')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->uuid('notifiable_id')->nullable()->change();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('started_by');
            $table->dropConstrainedForeignId('completed_by');
            $table->dropConstrainedForeignId('approver_id');
            $table->dropColumn('approval_note');
        });
    }
};
