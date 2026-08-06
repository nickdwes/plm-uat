<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a project needs beyond the record that one exists.
 *
 * Still deliberately short of a full project module. Tasks, milestones, time
 * and billing are their own thing — Tasks is already a separate entry in the
 * navigation, and building it here to satisfy a kickoff button would be the
 * tail wagging the dog.
 *
 * What is added is what a kickoff hands over: when it actually started and
 * finished, as distinct from when it was due, and who is on it. A deadline
 * alone cannot answer "was it late", which is the first question anybody asks
 * of a delivered project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->date('started_on')->nullable()->after('deadline');
            $table->date('delivered_on')->nullable()->after('started_on');

            // Carried from the deal so a project can be read without opening
            // the opportunity it came from. Not derived at read time: the deal's
            // value can change afterwards, and what the project was scoped
            // against should not move with it.
            $table->decimal('contract_value', 15, 2)->nullable()->after('delivered_on');
        });

        Schema::create('project_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('role')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_team');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['started_on', 'delivered_on', 'contract_value']);
        });
    }
};
