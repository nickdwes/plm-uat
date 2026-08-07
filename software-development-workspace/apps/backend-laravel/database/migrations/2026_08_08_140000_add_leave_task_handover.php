<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Covering somebody's work while they are away — and knowing what cannot be.
 *
 * The legacy's `leave_tasks` is the biggest cross-module thing it had: 4,361
 * rows against 548 leave requests and 1,660 distinct tasks, every one pointing
 * at a task that already existed and naming somebody to cover it. Roughly eight
 * tasks handed over per leave request. It is a handover register, not a
 * generator — which is why nothing here creates a task.
 *
 * What it got wrong was treating every task as equally handable. It listed all
 * of them and asked for a name against each, and the result is 4,361 rows whose
 * notes are mostly the word "Test" — a form people learned to fill in without
 * reading, because most of what it asked was nonsense.
 *
 * Most work cannot be handed to somebody else for a fortnight:
 *
 *   Training and certificates are yours. Nobody can sit your exam.
 *
 *   Research is a thread. Somebody picking it up halfway does not inherit the
 *   reading, and hands back something that has to be redone.
 *
 *   Meeting actions belong to whoever was in the room. The context is the
 *   reason the action exists.
 *
 *   Project work belongs to the project plan. Reallocating it is the project
 *   manager's decision about resourcing, not something to settle in a leave
 *   form the day before somebody flies.
 *
 * What genuinely can be covered is the customer-facing and time-critical work —
 * support, tickets, accounts and live deals — where the cost of waiting a
 * fortnight lands on somebody outside the company.
 *
 * The category decides by default and a task can say otherwise, because the
 * default is a good guess and not a law: a self-raised task might be personal
 * admin or might be the one thing holding up a delivery, and only the person
 * going away knows which.
 *
 * The point of separating them is not tidiness. It is that a task nobody can
 * cover, falling due while its owner is away, is a problem that needs deciding
 * before they go — moved, finished early, or knowingly allowed to slip. The
 * legacy could not ask that question because it could not tell the two apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            /*
             * Whether this one can be covered: transferable | personal.
             *
             * Nullable, and null is the meaningful state — it means "follow the
             * default for this kind of work". Writing the default into every
             * row would freeze it, so changing the policy later would fix
             * nothing already created.
             */
            $table->string('handover_policy', 16)->nullable()->after('source');
        });

        /*
         * Who is covering what, and until when.
         *
         * A record rather than reassigning the task. Overwriting `assigned_to`
         * would lose who it goes back to, and after two consecutive leaves
         * nobody could say whose task it originally was.
         */
        Schema::create('task_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();

            // Usually leave, but not always — somebody seconded to a customer
            // site for a month has the same problem and no leave request.
            $table->foreignId('leave_request_id')->nullable()
                ->constrained('leave_requests')->cascadeOnDelete();

            $table->foreignId('from_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('to_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->text('note')->nullable();

            $table->date('starts_on');
            $table->date('ends_on');

            // Stamped on return. Absent means the cover is still standing,
            // which is what makes "who is holding this today" answerable.
            $table->timestamp('released_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One cover per task per leave. Naming somebody else replaces the
            // first rather than leaving two people each assuming the other.
            $table->unique(['task_id', 'leave_request_id']);
            $table->index(['to_employee_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_handovers');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('handover_policy');
        });
    }
};
