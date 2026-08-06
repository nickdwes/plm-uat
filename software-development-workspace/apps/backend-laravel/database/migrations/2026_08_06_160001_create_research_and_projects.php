<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Research, the opportunity's own timeline, and the project a won deal starts.
 *
 * Research is the legacy's `mdl_projects_research` — twenty rows, its own nav
 * entry, and a tab on the opportunity. It sits between a lead and a deal: work
 * done to find out whether the thing can be built at all.
 *
 * Projects is deliberately thin. A full project module is its own piece of
 * work; what is needed here is the record that a won deal produced one, with
 * the scope, SLA and bill of materials attached to it. Building the whole of
 * Projects to satisfy a kickoff button would be the tail wagging the dog, and
 * a thin table now is far easier to grow than a wrong one is to unpick.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('researches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            // All three optional and independent: research can be raised
            // against a customer before any lead exists, and against a lead
            // before it becomes a deal.
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('opportunities')->nullOnDelete();

            $table->string('reference', 32)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('findings')->nullable();

            $table->string('status', 24)->default('new');      // new | in_progress | complete | abandoned
            $table->string('priority', 16)->default('medium');
            $table->date('deadline')->nullable();

            $table->foreignId('research_lead_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'reference']);
            $table->index(['opportunity_id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('research_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('research_id')->constrained('researches')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('role')->nullable();
            $table->timestamps();

            $table->unique(['research_id', 'employee_id']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('opportunities')->nullOnDelete();

            $table->string('reference', 32)->nullable();
            $table->string('name');
            $table->text('description')->nullable();

            $table->string('status', 24)->default('kickoff');
            $table->string('priority', 16)->default('medium');
            $table->date('deadline')->nullable();

            $table->foreignId('project_lead_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'reference']);
            $table->index(['opportunity_id']);
        });

        /*
         * What has happened on this deal.
         *
         * Separate from the customer's timeline and from the lead's, for the
         * same reason those are separate from each other: a deal stalling
         * while the customer is active elsewhere is the case worth seeing, and
         * one merged feed hides it.
         */
        Schema::create('opportunity_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();

            $table->string('reference', 32)->nullable();
            $table->string('kind', 16)->default('manual');    // manual | system
            $table->string('type', 32)->default('note');
            $table->text('body')->nullable();

            // Time of day is shown on the screen, so it is stored — a date
            // alone cannot order two things that happened the same morning.
            $table->timestamp('occurred_at');

            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['opportunity_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_activities');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('research_team');
        Schema::dropIfExists('researches');
    }
};
