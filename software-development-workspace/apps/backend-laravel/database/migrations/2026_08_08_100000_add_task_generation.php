<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks the system raises, rather than somebody typing them.
 *
 * A third of the legacy's 9,055 tasks were not typed by the person doing them.
 * Its `source` column splits Self 4,616, PLM 3,288, Project 1,004, Research 44,
 * oem 41, Support 34, Meeting 14, Opportunity 12 — and the ones that are
 * genuinely machine-raised are visible in what they link to: all 1,004 of the
 * Project ones carry a project, all 34 Support ones a ticket, all 41 oem ones a
 * certificate. It kept the distinction and never used it for anything, which is
 * why nobody could tell a task they had been given from one they had written.
 *
 * Two things are needed before any generator is safe to write, and both are
 * here rather than in the first generator that happens to need them:
 *
 *   `source`, so a task can say where it came from. Without it a generated task
 *   is indistinguishable from a typed one, and the first person to tidy up
 *   deletes work the system will silently raise again.
 *
 *   `generated_from`, with a unique key. This is the whole thing. A generator
 *   that runs on a schedule runs twice — after a deploy, after a retry, after
 *   somebody reruns it by hand — and without a key that says "this exact thing
 *   already produced this exact task" it produces a duplicate every time. The
 *   uniqueness is enforced by the database rather than by a check-then-insert,
 *   because two workers can both pass a check.
 *
 * What is deliberately not built here: leave. The obvious guess is that
 * approving leave raises tasks, and the legacy's `leave_tasks` table is the
 * biggest cross-module thing it had — 4,361 rows against 548 leave requests.
 * But reading it, every row points at a task that already existed and names
 * somebody to cover it. It is a handover register, not a generator, and
 * building it as generation would create 4,361 duplicate tasks where the legacy
 * had none. That is its own feature and is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            /*
             * Where it came from. Defaults to manual, which is what somebody
             * typing into the New Task form means.
             */
            $table->string('source', 32)->default('manual')->after('category');

            // What produced it, so the same thing never produces it twice.
            $table->string('generated_from_type', 64)->nullable()->after('source');
            $table->string('generated_from_id', 64)->nullable()->after('generated_from_type');

            /*
             * The idempotency key.
             *
             * Enforced by the database rather than a check-then-insert, because
             * two workers can both pass a check and both insert. Scoped by
             * organisation so two tenants can generate from the same period
             * key without colliding.
             */
            $table->unique(
                ['organization_id', 'generated_from_type', 'generated_from_id'],
                'tasks_generated_from_unique',
            );

            $table->index(['organization_id', 'source']);
        });

        /*
         * Work that comes back round.
         *
         * The legacy had no recurrence at all — no table, no column, nothing —
         * which is why its 9,055 tasks include the same certificate renewals
         * and monthly reviews typed out by hand over and over. This is the one
         * generator that needs no other module to exist, and the one whose
         * absence people work around daily.
         *
         * A template rather than copies made in advance: a year of monthly
         * tasks created up front is a year of clutter, and every edit to the
         * template would have to chase twelve rows that already exist.
         */
        Schema::create('task_recurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            // What to raise. The same shape as the task it will produce.
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 32)->default('internal');
            $table->string('priority', 16)->default('medium');
            $table->foreignId('assigned_to')->nullable()->constrained('employees')->nullOnDelete();
            $table->decimal('effort_estimate_days', 6, 2)->nullable();

            // Optional context, carried onto every task it raises.
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();

            // How often: daily | weekly | monthly | quarterly | yearly.
            $table->string('frequency', 16);
            $table->unsignedSmallInteger('interval')->default(1);

            /*
             * How long the person gets once it appears.
             *
             * Days rather than a fixed date, because the deadline of a repeating
             * task is relative to the occurrence — "within a week of the first
             * of the month", not "the eighth of January forever".
             */
            $table->unsignedSmallInteger('lead_days')->default(0);

            $table->date('starts_on');
            // Null runs forever, which is the honest default for a renewal.
            $table->date('ends_on')->nullable();

            // Stopped without being deleted, so its history stays readable.
            $table->boolean('is_active')->default(true);

            // What it last produced, so the next due date is answerable without
            // scanning every task it has ever raised.
            $table->date('last_generated_for')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_recurrences');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique('tasks_generated_from_unique');
            $table->dropIndex(['organization_id', 'source']);
            $table->dropColumn(['source', 'generated_from_type', 'generated_from_id']);
        });
    }
};
