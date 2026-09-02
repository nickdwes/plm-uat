<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things a task could never say: who it is waiting on, and what came of it.
 *
 * `owner_party` already records who owes the work — a commercial fact that
 * holds for the life of the task. Responsibility is the other question, and it
 * changes by the hour: the work is ours, but right now it sits with the client
 * for sign-off, and next week with procurement. Kept apart deliberately, because
 * collapsing them would make "who owes this" unanswerable the moment somebody
 * else is holding it up.
 *
 * The closure summary is the other end. A task that goes from in-progress to
 * completed leaves nothing behind saying what was delivered or what got in the
 * way, so a project review three months later has a date and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('responsibility', 32)->nullable()->after('owner_contact_name');
            $table->index(['organization_id', 'responsibility']);
        });

        Schema::create('task_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // One closure per task: finishing something twice is not a thing.
            $table->foreignId('task_id')->unique()->constrained()->cascadeOnDelete();

            $table->text('work_completed');
            $table->text('deliverables');
            $table->text('challenges');
            $table->text('outcomes');
            // The one field that is genuinely optional — plenty of tasks teach
            // nobody anything, and demanding a lesson invents one.
            $table->text('lessons_learned')->nullable();

            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at');
            $table->timestamps();

            $table->index(['organization_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_closures');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'responsibility']);
            $table->dropColumn('responsibility');
        });
    }
};
