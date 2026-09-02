<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A task can be the surface a meeting is scheduled from.
 *
 * A meeting still lives where it is tracked — a project task's call on the
 * project (its Correspondence tab), a support task's call on the ticket (its
 * Meetings tab) — so `task_id` is added to both of those tables to record which
 * task raised it, without moving the meeting off its home.
 *
 * A task tied to neither has nowhere else to keep the call, so `task_meetings`
 * gives it one, mirroring the other two meeting tables exactly.
 *
 * Additive: the two new columns are nullable, and the new table is created only
 * if absent. Nothing existing is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['project_meetings', 'support_ticket_meetings'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'task_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->foreignId('task_id')->nullable()->after('organization_id')
                        ->constrained('tasks')->nullOnDelete();
                });
            }
        }

        if (! Schema::hasTable('task_meetings')) {
            Schema::create('task_meetings', function (Blueprint $t) {
                $t->id();
                $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $t->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
                $t->string('subject', 300);
                $t->timestamp('scheduled_at');
                $t->unsignedSmallInteger('duration_minutes');
                $t->json('attendees')->nullable();
                $t->string('graph_event_id')->nullable();
                $t->string('join_url', 500)->nullable();
                $t->string('recording_url', 500)->nullable();
                $t->longText('transcript')->nullable();
                $t->string('status', 16)->default('scheduled');
                $t->timestamp('cancelled_at')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();

                $t->index(['task_id', 'scheduled_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_meetings');

        foreach (['project_meetings', 'support_ticket_meetings'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'task_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropConstrainedForeignId('task_id');
                });
            }
        }
    }
};
