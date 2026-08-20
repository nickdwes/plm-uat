<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Causes, deliberate changes, and somebody actually signing things off.
 *
 * ── Problems are not incidents ──
 *
 * The old module had "Problem" as a ticket type, which makes a cause into one
 * more thing in the queue. Forty tickets about the same failing switch were
 * forty separate pieces of work, each closed on its own, and nothing recorded
 * that they shared a cause — so the switch stayed in.
 *
 * ── Approvals had statuses and no engine ──
 *
 * "Awaiting Approval" and "Purchase Order Approval" existed as words on a
 * ticket. Nothing asked anybody, nothing recorded an answer, and nothing
 * stopped work starting anyway. This is the machinery those words were
 * describing.
 *
 * Modelled on the travel approval stages that already exist, rather than a
 * second convention: two approval systems in one codebase means two places to
 * look when somebody asks why a thing is stuck.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Problems ──────────────────────────────────────────────────────────

        Schema::create('support_problems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 24);
            $table->string('title', 300);
            $table->text('statement')->nullable();

            /*
             * investigating | known_error | resolved | closed
             *
             * known_error is the state that earns its keep: cause understood,
             * fix not yet possible, and a workaround that the desk should be
             * giving out in the meantime rather than rediscovering each time.
             */
            $table->string('status', 16)->default('investigating');

            $table->text('root_cause')->nullable();
            $table->text('workaround')->nullable();

            $table->foreignId('service_category_id')->nullable()
                ->constrained('support_service_categories')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('identified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'status']);
        });

        // ── Changes ───────────────────────────────────────────────────────────

        Schema::create('support_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 24);
            $table->string('title', 300);
            $table->text('description')->nullable();

            /*
             * standard  — pre-approved and routine, no CAB
             * normal    — needs approval before the window
             * emergency — done first, approved after, and recorded as such
             *
             * The third is the honest one. Emergencies happen, and a process
             * with no route for them gets bypassed entirely rather than
             * followed badly.
             */
            $table->string('change_type', 12)->default('normal');

            // low | medium | high
            $table->string('risk', 8)->default('medium');

            $table->text('implementation_plan')->nullable();

            /*
             * Required on anything but a standard change. "What do we do if
             * this goes wrong" is the question nobody asks at 2am, and the
             * answer has to exist before the window opens.
             */
            $table->text('rollback_plan')->nullable();

            // draft | awaiting_approval | approved | rejected | scheduled |
            // in_progress | implemented | failed | cancelled
            $table->string('status', 20)->default('draft');

            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();

            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('support_problem_id')->nullable()
                ->constrained('support_problems')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('implementer_id')->nullable()->constrained('users')->nullOnDelete();

            /** Written afterwards. A change with no outcome is a change nobody learns from. */
            $table->text('outcome')->nullable();
            $table->timestamp('implemented_at')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'status']);
            $table->index('window_start');
        });

        /** Which tickets a problem or change accounts for. */
        Schema::create('support_ticket_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('support_problem_id')->nullable()
                ->constrained('support_problems')->cascadeOnDelete();
            $table->foreignId('support_change_id')->nullable()
                ->constrained('support_changes')->cascadeOnDelete();

            // caused_by | resolved_by | related
            $table->string('relationship', 12)->default('related');
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['support_problem_id', 'relationship']);
            $table->index('support_change_id');
        });

        // ── Approvals ─────────────────────────────────────────────────────────

        /**
         * Who has to sign off on what.
         *
         * Follows the travel approval stages already in the system rather than
         * inventing a second convention — two approval systems means two places
         * to look when somebody asks why a thing is stuck.
         */
        Schema::create('support_approval_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('stage', 60);
            $table->unsignedSmallInteger('sequence')->default(1);

            // change | service_request — what this stage applies to
            $table->string('applies_to', 20);

            /** Narrower still: only high-risk changes, only one service. */
            $table->string('risk', 8)->nullable();
            $table->foreignId('support_service_id')->nullable()
                ->constrained('support_services')->cascadeOnDelete();

            /*
             * Who is asked. A role rather than a person, because the person
             * changes and a stage naming somebody who left is a stage nothing
             * can satisfy.
             */
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'applies_to', 'sequence']);
        });

        Schema::create('support_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            /*
             * Polymorphic across changes and tickets, because a catalogue
             * request needing a manager's sign-off and a change needing the CAB
             * are the same act with the same audit trail.
             */
            $table->string('approvable_type', 60);
            $table->unsignedBigInteger('approvable_id');

            $table->string('stage', 60);
            $table->unsignedSmallInteger('sequence')->default(1);

            $table->foreignId('expected_approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();

            // pending | approved | rejected | skipped
            $table->string('status', 12)->default('pending');

            /*
             * Required on a rejection. "Rejected" with no reason sends the
             * requester back to ask what was wrong, which is the conversation
             * the approval was supposed to have already had.
             */
            $table->text('comment')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->index(['approvable_type', 'approvable_id', 'sequence']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_approvals');
        Schema::dropIfExists('support_approval_stages');
        Schema::dropIfExists('support_ticket_links');
        Schema::dropIfExists('support_changes');
        Schema::dropIfExists('support_problems');
    }
};
