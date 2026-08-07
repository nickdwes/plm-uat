<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planning the day — what you intend to spend it on.
 *
 * The designs settle the one question that mattered here. The Add Task modal
 * offers Project Name and Task Name as dropdowns, not text fields: you are
 * picking work that already exists and giving it a slot, not writing a second
 * copy of it somewhere the Tasks module cannot see. So a block points at a task
 * rather than describing one, and "tasks planned today" and "tasks completed
 * today" are then two readings of the same records instead of two systems that
 * quietly disagree.
 *
 * Not every block is a task, though. The designs show Break, Lunch, Check-in
 * and Review alongside the real work, and they are right to: a day planned as
 * eight solid hours of deliverables is not a plan anybody keeps. So the task
 * link is optional and a block can simply be a named span.
 *
 * The requirement asks for the number of tasks planned per day against the
 * number completed. Both fall out of this without a counter to maintain —
 * planned is the distinct tasks blocked on the day, completed is read from the
 * tasks themselves. A stored count would be a count that drifts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('attendance_day_id')->constrained('attendance_days')->cascadeOnDelete();

            /*
             * The work, where there is any.
             *
             * Nullable because Break and Lunch are real parts of a day and
             * forcing a task behind each one would fill the task list with
             * rubbish nobody wants to see. Cascades on delete: a block pointing
             * at a task that no longer exists is a slot for nothing.
             */
            $table->foreignId('task_id')->nullable()->constrained('tasks')->cascadeOnDelete();

            /*
             * What to call it.
             *
             * Held even when a task is linked, so the timeline still reads
             * after the task is renamed or removed — and so one task split
             * across a morning and an afternoon can say which half is which.
             */
            $table->string('title');

            // work | meeting | break | admin | enablement
            $table->string('kind', 24)->default('work');

            $table->time('starts_at');
            $table->time('ends_at');

            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The timeline reads a day in order; the reporting reads a task
            // across days.
            $table->index(['attendance_day_id', 'starts_at']);
            $table->index(['task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_blocks');
    }
};
