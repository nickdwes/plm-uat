<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets go to the engineer who knows the customer, not to whoever is next.
 *
 * Round-robin and least-busy are removed. Both distribute work by availability
 * and ignore the thing that actually matters — who holds the contract, knows
 * the estate and has the competency. An engineer who has never seen a
 * customer's network is not interchangeable with one who installed it, and a
 * queue that pretends otherwise makes every ticket slower.
 *
 * ── Three axes, not twelve categories ──
 *
 * What kind of work it is (type), which discipline owns it (service category)
 * and where it currently sits (escalation) are three independent facts. Flattened
 * into one list, "a network incident" cannot be said — it has to be filed as
 * either network or incident — and every rule and every report is worse for it.
 *
 * ── Reuse, not a parallel org ──
 *
 * OrgFunctionalUnit → OrgBusinessUnit → OrgPractice already exists with leads.
 * Support queues point at a practice rather than restating the hierarchy: two
 * structures means two places to add an engineer, and one that does not get
 * updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Which discipline owns the work ────────────────────────────────────

        Schema::create('support_service_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 120);

            /** The practice that owns this discipline, where there is one. */
            $table->foreignId('org_practice_id')->nullable()
                ->constrained('org_practices')->nullOnDelete();

            /*
             * Whether this needs an engineer at all. Billing queries and
             * contract questions are support work and must not be forced into
             * a technical engineer's queue.
             */
            $table->boolean('is_technical')->default(true);

            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
        });

        // ── Queues point at the org, rather than restating it ─────────────────

        Schema::table('support_teams', function (Blueprint $table) {
            $table->foreignId('org_practice_id')->nullable()->after('description')
                ->constrained('org_practices')->nullOnDelete();
            $table->foreignId('org_business_unit_id')->nullable()->after('org_practice_id')
                ->constrained('org_business_units')->nullOnDelete();

            /*
             * The desk's own queue. Marked rather than matched by name, because
             * routing falls back to it and a queue found by its title is a
             * queue that breaks when somebody renames it.
             */
            $table->boolean('is_service_desk')->default(false)->after('is_default');

            /** Vendor and supplier escalation is a queue, not a practice. */
            $table->boolean('is_vendor_queue')->default(false)->after('is_service_desk');
        });

        // ── Who knows this customer ───────────────────────────────────────────

        /**
         * The support structure attached to a contract.
         *
         * One row per contract. Separate from the contract itself because a
         * contract is a commercial record owned by the accounts side, and its
         * support arrangements change far more often than its terms.
         */
        Schema::create('account_contract_support', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_contract_id')->unique()
                ->constrained('account_contracts')->cascadeOnDelete();

            $table->foreignId('primary_team_id')->nullable()
                ->constrained('support_teams')->nullOnDelete();
            $table->foreignId('escalation_team_id')->nullable()
                ->constrained('support_teams')->nullOnDelete();

            /** The analyst who follows this customer without owning the fixes. */
            $table->foreignId('service_desk_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('vendor_contact_name', 160)->nullable();
            $table->string('vendor_contact_email', 200)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        /**
         * Engineers named on a contract.
         *
         * A category may be set, so a contract can name a network engineer and
         * a cloud engineer separately — which is how routing picks the right
         * one rather than the only one.
         */
        Schema::create('account_contract_engineers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_contract_id')->constrained('account_contracts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // primary | secondary
            $table->string('role', 12)->default('primary');

            $table->foreignId('service_category_id')->nullable()
                ->constrained('support_service_categories')->nullOnDelete();

            $table->string('notes', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['account_contract_id', 'user_id', 'service_category_id'], 'contract_engineer_unique');
        });

        // ── What an engineer can actually take on ─────────────────────────────

        Schema::create('support_engineers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->foreignId('org_practice_id')->nullable()
                ->constrained('org_practices')->nullOnDelete();

            /*
             * Free-form tags — fortinet, m365, mpls. Deliberately not a
             * certification matrix with levels and expiry dates: that is only
             * more accurate if somebody keeps it current, and the ones that go
             * stale are worse than tags nobody promised were authoritative.
             */
            $table->json('skills')->nullable();

            /** On leave, or simply not on the support rota this week. */
            $table->boolean('accepts_assignment')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
        });

        // ── Ownership, and a record of every hand-off ─────────────────────────

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->foreignId('service_category_id')->nullable()->after('ticket_type_id')
                ->constrained('support_service_categories')->nullOnDelete();

            /*
             * Managing a ticket and owning the fix are different jobs. The
             * analyst chases it, the engineer fixes it, and conflating them is
             * how the desk ends up technically responsible for everything.
             */
            $table->foreignId('service_desk_user_id')->nullable()->after('assigned_to')
                ->constrained('users')->nullOnDelete();

            /** Who the current escalation sits with, where one is open. */
            $table->foreignId('escalation_owner_id')->nullable()->after('service_desk_user_id')
                ->constrained('users')->nullOnDelete();

            $table->foreignId('account_contract_engineer_id')->nullable()->after('escalation_owner_id')
                ->constrained('account_contract_engineers')->nullOnDelete();

            $table->index(['organization_id', 'service_category_id']);
            $table->index(['organization_id', 'service_desk_user_id']);
        });

        /**
         * A deliberate escalation, which is not the SLA ladder.
         *
         * The HOD → CTO → CEO ladder fires on a clock and says a target is at
         * risk. This says somebody decided the work needs different hands.
         * Different triggers, different audiences and different questions
         * afterwards, so they are kept apart rather than sharing a table and
         * a meaning.
         */
        Schema::create('support_escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();

            // specialist | team_lead | business_unit | vendor | service_desk
            $table->string('level', 20);

            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('from_team_id')->nullable()->constrained('support_teams')->nullOnDelete();
            $table->foreignId('to_team_id')->nullable()->constrained('support_teams')->nullOnDelete();

            $table->text('reason');
            $table->foreignId('escalated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('escalated_at');

            /** Closed off when the specialist hands it back, with what they found. */
            $table->timestamp('resolved_at')->nullable();
            $table->text('outcome')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['support_ticket_id', 'resolved_at']);
        });

        /**
         * Every hand-off, in order.
         *
         * Assignment and routing have to be auditable, and "who sent this to me
         * and why" cannot be answered from a column that only holds the current
         * value.
         */
        Schema::create('support_ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();

            // assigned | reassigned | queued | classified | escalated | de-escalated | triaged
            $table->string('event', 20);

            $table->string('field', 40)->nullable();
            $table->string('from_value', 200)->nullable();
            $table->string('to_value', 200)->nullable();
            $table->string('reason', 500)->nullable();

            /** Null where the routing engine did it rather than a person. */
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('automatic')->default(false);

            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['support_ticket_id', 'event']);
        });

        // ── Retire the modes that ignore who knows the customer ───────────────

        Schema::table('support_assignment_rules', function (Blueprint $table) {
            $table->foreignId('match_service_category_id')->nullable()->after('match_priority_id')
                ->constrained('support_service_categories')->nullOnDelete();

            /*
             * contract_engineer — the engineer named on the customer's contract
             * for this category, which is the default and the point of all this
             * team_only        — leave it on the queue for the team to pick up
             * specific         — one named person, for genuine exceptions
             */
            $table->string('prefer', 20)->default('contract_engineer')->after('assign_mode');
        });

        /*
         * Existing rules are converted rather than left pointing at behaviour
         * that no longer exists. Anything that was sharing work out becomes a
         * queue assignment: the team still receives it, and a human picks.
         */
        DB::table('support_assignment_rules')
            ->whereIn('assign_mode', ['round_robin', 'least_busy'])
            ->update(['assign_mode' => 'none', 'prefer' => 'contract_engineer']);
    }

    public function down(): void
    {
        Schema::table('support_assignment_rules', function (Blueprint $table) {
            $table->dropForeign(['match_service_category_id']);
            $table->dropColumn(['match_service_category_id', 'prefer']);
        });

        Schema::dropIfExists('support_ticket_events');
        Schema::dropIfExists('support_escalations');

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropForeign(['service_category_id']);
            $table->dropForeign(['service_desk_user_id']);
            $table->dropForeign(['escalation_owner_id']);
            $table->dropForeign(['account_contract_engineer_id']);
            $table->dropColumn([
                'service_category_id', 'service_desk_user_id',
                'escalation_owner_id', 'account_contract_engineer_id',
            ]);
        });

        Schema::dropIfExists('support_engineers');
        Schema::dropIfExists('account_contract_engineers');
        Schema::dropIfExists('account_contract_support');

        Schema::table('support_teams', function (Blueprint $table) {
            $table->dropForeign(['org_practice_id']);
            $table->dropForeign(['org_business_unit_id']);
            $table->dropColumn(['org_practice_id', 'org_business_unit_id', 'is_service_desk', 'is_vendor_queue']);
        });

        Schema::dropIfExists('support_service_categories');
    }
};
