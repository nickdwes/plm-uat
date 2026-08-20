<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rules that do the obvious thing so nobody has to.
 *
 * All triage in the old module was manual: somebody read every ticket, set
 * every priority and chased every parked one, which is a person's morning and
 * the reason things sat in "With User" for three weeks.
 *
 * ── Why every firing is recorded ──
 *
 * "Why did this ticket close itself?" has to be answerable, and it cannot be
 * from a rule definition alone — the rule may have changed since, and the
 * ticket may have matched for a reason nobody would guess. A run log costs a
 * row per firing and is the difference between automation people trust and
 * automation they turn off.
 *
 * ── Why cooldowns exist ──
 *
 * A scheduled rule that chases a customer is harassment at fifteen-minute
 * intervals. Anything that reaches outside the building needs to say how often
 * it may do so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name', 160);
            $table->string('description', 400)->nullable();

            // created | updated | scheduled
            $table->string('trigger', 12);

            /*
             * Conditions and actions as JSON.
             *
             * Read and applied whole, never queried across. The run log below
             * is what gets queried — "which rules fired on this ticket" is a
             * question about firings, not about definitions.
             */
            $table->json('conditions')->nullable();
            $table->json('actions');

            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(false);

            /*
             * Armed separately from being written. A rule that closes tickets
             * should be readable, arguable and testable before it is allowed
             * to touch anything.
             */
            $table->boolean('is_dry_run')->default(true);

            /** A rule that has already done its job to a ticket leaves it alone. */
            $table->boolean('once_per_ticket')->default(true);

            /** How long before it may act on the same ticket again. */
            $table->unsignedSmallInteger('cooldown_hours')->nullable();

            $table->unsignedInteger('fired_count')->default(0);
            $table->timestamp('last_fired_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'trigger', 'is_active']);
        });

        /**
         * What actually happened, per firing.
         *
         * Written whether the rule acted or only would have, so a dry run is a
         * genuine rehearsal rather than a guess.
         */
        Schema::create('support_automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_automation_id')->constrained('support_automations')->cascadeOnDelete();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();

            $table->boolean('was_dry_run')->default(false);

            /** In words. "Set priority to High; assigned to the network queue." */
            $table->string('summary', 500);

            $table->json('actions')->nullable();
            $table->timestamp('fired_at');
            $table->timestamps();

            $table->index(['support_automation_id', 'support_ticket_id']);
            $table->index(['support_ticket_id', 'fired_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_automation_runs');
        Schema::dropIfExists('support_automations');
    }
};
