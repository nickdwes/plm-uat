<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks stop belonging to projects.
 *
 * I built `project_tasks` as the project's work breakdown, which the design's
 * task board supported. The legacy data says that is a quarter of the story:
 * of its 9,055 tasks only 25% carry a project, and its twelve categories are
 * the module map — Opportunity, Project, Support, Meetings, Training,
 * Certificate, Research, Collaboration, Internal, Ticket, Account, Leave.
 * Tasks are the spine of the system, not a feature of one module. Both
 * predecessors modelled it that way and the agreed navigation puts Tasks in its
 * own section.
 *
 * So `project_id` becomes nullable, the other module links come across, and the
 * table is renamed. No production rows exist, so this is a restructure rather
 * than a migration of data.
 *
 * What also comes across, because the legacy used it heavily and none of it was
 * modelled:
 *
 *   Effort. 90% of its tasks carried a work-effort estimate and 91% an end
 *   date, and nothing ever compared them. Estimate against actual is the
 *   highest-value report available from six years of existing data.
 *
 *   Asking rather than assigning — see the task_requests table below.
 *
 *   Required attachments: a task naming the documents it cannot close without.
 */
return new class extends Migration
{
    /** Tables carrying a link to a task. */
    private const POINTERS = [
        'project_attachments', 'project_comments', 'project_activities',
        'project_contract_milestones',
    ];

    public function up(): void
    {
        Schema::rename('project_tasks', 'tasks');
        Schema::rename('project_task_members', 'task_members');
        Schema::rename('project_task_dependencies', 'task_dependencies');

        // The renamed tables still carry `project_task_id`, which no longer
        // says anything true — a task need not belong to a project.
        DB::statement('ALTER TABLE task_members RENAME COLUMN project_task_id TO task_id');
        DB::statement('ALTER TABLE task_dependencies RENAME COLUMN project_task_id TO task_id');

        Schema::table('tasks', function (Blueprint $table) {
            // A quarter of legacy tasks had a project. The rest are support,
            // training, meetings and plain internal work.
            $table->unsignedBigInteger('project_id')->nullable()->change();

            /*
             * What kind of work it is. The legacy's twelve categories, which
             * doubled as the module map — so this is both a filter and the
             * link back to whichever record raised it.
             */
            $table->string('category', 32)->default('internal')->after('project_id');

            $table->foreignId('opportunity_id')->nullable()->after('category')
                ->constrained('opportunities')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->after('opportunity_id')
                ->constrained('leads')->nullOnDelete();
            $table->foreignId('research_id')->nullable()->after('lead_id')
                ->constrained('researches')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->after('research_id')
                ->constrained('accounts')->nullOnDelete();

            /*
             * Effort and elapsed time.
             *
             * Estimated days against what was actually spent. The legacy had
             * both on nine tasks in ten and never once compared them; that
             * comparison is the report this module exists to make possible.
             */
            $table->decimal('effort_estimate_days', 6, 2)->nullable()->after('deadline');
            $table->date('started_on')->nullable()->after('effort_estimate_days');
            $table->foreignId('assigned_to')->nullable()->after('started_on')
                ->constrained('employees')->nullOnDelete();

            // Whether somebody had to sign it off. 24% of legacy tasks were
            // approved, so it is real but not universal.
            $table->boolean('requires_approval')->default(false)->after('completed_at');
            $table->timestamp('approved_at')->nullable()->after('requires_approval');
            $table->foreignId('approved_by')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['organization_id', 'category']);
            $table->index(['assigned_to', 'status']);
        });

        /*
         * Asking somebody rather than assigning them.
         *
         * The legacy had three tables of identical shape — helping (2,566),
         * delegations (354) and collaborations (9) — each a request with a
         * comment, a status and a response. One table with a `kind` here,
         * because three copies of the same eight columns is how two of them
         * end up missing a feature the third gained.
         *
         * The data is worth reading before deciding this matters: helping was
         * accepted 98% of the time and clearly works; delegation sat at 74%
         * still Pending, which is a workflow nobody answered. Both are kept —
         * the unanswered ones are themselves the finding, and a screen that
         * shows them is the fix.
         */
        Schema::create('task_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();

            $table->string('kind', 16);          // assist | delegate | collaborate
            $table->foreignId('to_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('employees')->nullOnDelete();

            $table->text('request_comment')->nullable();
            $table->string('status', 16)->default('pending');   // pending | accepted | declined
            $table->text('response_comment')->nullable();
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            // One open request of a kind per person per task; asking twice is a
            // nudge, not a second request.
            $table->unique(['task_id', 'to_employee_id', 'kind']);
            $table->index(['to_employee_id', 'status']);
        });

        /*
         * Documents a task cannot close without.
         *
         * Named up front and fulfilled later — 344 required on the legacy, 247
         * of them actually supplied. The gap is the point: "this task needs a
         * signed ToR" is a checklist item whether or not the document exists
         * yet, and a task list that cannot express it silently loses the
         * requirement.
         */
        Schema::create('task_required_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();

            $table->string('name');
            $table->timestamp('fulfilled_at')->nullable();

            /*
             * Two ways to satisfy it, because a task need not have a project.
             *
             * Pointing at a project attachment works only where there is a
             * project, and three quarters of tasks have none — so the slot
             * carries its own file too. Both are nullable; the requirement is
             * a checklist item whether or not anything has arrived yet.
             */
            $table->foreignId('project_attachment_id')->nullable()
                ->constrained('project_attachments')->nullOnDelete();

            $table->string('original_name')->nullable();
            $table->string('storage_path')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['task_id']);
        });

        // The dependency's own kind and standing, both of which the legacy
        // carried: 172 of its 203 were "Custom" rather than a plain task link.
        Schema::table('task_dependencies', function (Blueprint $table) {
            $table->string('kind', 24)->default('task')->after('depends_on_task_id');
            $table->string('note')->nullable()->after('kind');
        });

        // Everything pointing at a task follows the rename — the column no
        // longer names a project's task, it names a task.
        foreach (self::POINTERS as $t) {
            DB::statement("ALTER TABLE {$t} RENAME COLUMN project_task_id TO task_id");
        }
    }

    public function down(): void
    {
        foreach (self::POINTERS as $t) {
            DB::statement("ALTER TABLE {$t} RENAME COLUMN task_id TO project_task_id");
        }

        Schema::table('task_dependencies', function (Blueprint $table) {
            $table->dropColumn(['kind', 'note']);
        });

        Schema::dropIfExists('task_required_documents');
        Schema::dropIfExists('task_requests');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opportunity_id');
            $table->dropConstrainedForeignId('lead_id');
            $table->dropConstrainedForeignId('research_id');
            $table->dropConstrainedForeignId('account_id');
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn([
                'category', 'effort_estimate_days', 'started_on',
                'requires_approval', 'approved_at',
            ]);
        });

        DB::statement('ALTER TABLE task_dependencies RENAME COLUMN task_id TO project_task_id');
        DB::statement('ALTER TABLE task_members RENAME COLUMN task_id TO project_task_id');

        Schema::rename('task_dependencies', 'project_task_dependencies');
        Schema::rename('task_members', 'project_task_members');
        Schema::rename('tasks', 'project_tasks');
    }
};
