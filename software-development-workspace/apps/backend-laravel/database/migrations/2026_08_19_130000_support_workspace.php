<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The desk as somewhere to work, rather than a table of tickets.
 *
 * Thirteen internal notes across 1,792 tickets is the measure of what the old
 * module was: a record of correspondence that never supported the job. This is
 * the machinery around that — who work belongs to, how it gets there, the
 * answers people retype, and the fact that one outage is not forty faults.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Who work belongs to ───────────────────────────────────────────────

        Schema::create('support_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 120);
            $table->string('description', 255)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
        });

        Schema::create('support_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_team_id')->constrained('support_teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_lead')->default(false);

            /*
             * Somebody on leave stays on the team and stops receiving work.
             * Removing them instead would lose the fact that they belong to it,
             * and they would come back to a team they are no longer in.
             */
            $table->boolean('accepts_assignment')->default(true);

            $table->timestamps();

            $table->unique(['support_team_id', 'user_id']);
        });

        // ── How work gets there ───────────────────────────────────────────────

        /**
         * First matching rule wins, in position order.
         *
         * Deliberately not a rules engine. Four conditions cover the routing a
         * desk this size actually does — by account, by type, by the sender's
         * domain, by a word in the subject — and a rule nobody can read is a
         * rule nobody can debug at eight in the morning.
         */
        Schema::create('support_assignment_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            // Conditions. All that are set must match; unset ones are ignored.
            $table->foreignId('match_account_id')->nullable()->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('match_type_id')->nullable()
                ->constrained('support_ticket_types')->cascadeOnDelete();
            $table->foreignId('match_priority_id')->nullable()
                ->constrained('support_ticket_priorities')->cascadeOnDelete();
            $table->string('match_domain', 200)->nullable();
            $table->string('match_keyword', 200)->nullable();

            // What to do about it.
            $table->foreignId('assign_team_id')->nullable()->constrained('support_teams')->nullOnDelete();

            // round_robin | least_busy | specific | none
            $table->string('assign_mode', 16)->default('none');
            $table->foreignId('assign_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['organization_id', 'position']);
        });

        // ── The answers people retype ─────────────────────────────────────────

        Schema::create('support_canned_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->longText('body');

            /** Null is available to everybody; a team id narrows it. */
            $table->foreignId('support_team_id')->nullable()->constrained('support_teams')->nullOnDelete();

            /*
             * How often it has been used, and when last. Not vanity: a canned
             * response nobody picks is one that reads badly, and the list sorts
             * by this so the good ones rise without anybody curating it.
             */
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('last_used_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // ── Personal queues ───────────────────────────────────────────────────

        Schema::create('support_saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            /** Null is shared with the whole desk. */
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->string('name', 120);
            $table->json('filters');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // ── The ticket learns about teams ─────────────────────────────────────

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->foreignId('support_team_id')->nullable()->after('assigned_to')
                ->constrained('support_teams')->nullOnDelete();

            /*
             * Set when a ticket is joined to a major incident. The parent link
             * alone cannot say whether a child was merged in or raised as a
             * sub-task, and un-merging needs to know which.
             */
            $table->timestamp('merged_at')->nullable()->after('parent_ticket_id');

            $table->index(['organization_id', 'support_team_id']);
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropForeign(['support_team_id']);
            $table->dropColumn(['support_team_id', 'merged_at']);
        });

        Schema::dropIfExists('support_saved_views');
        Schema::dropIfExists('support_canned_responses');
        Schema::dropIfExists('support_assignment_rules');
        Schema::dropIfExists('support_team_members');
        Schema::dropIfExists('support_teams');
    }
};
