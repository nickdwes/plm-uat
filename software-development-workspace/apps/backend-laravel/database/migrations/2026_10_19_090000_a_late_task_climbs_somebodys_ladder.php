<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who was told, about what, and when.
 *
 * Overdue work was visible on a board and nowhere else, so it climbed nobody's
 * desk unless somebody happened to look. This is the record of the climb.
 *
 * The unique key is the whole design. An escalation that re-fires every night
 * is one people filter into a folder, and then the ladder is decoration — so a
 * rung is a row, and a row exists once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();

            // What set it off, and how far up it went.
            $table->string('trigger', 32);
            $table->string('level', 32);

            // Null where the role exists but nobody holds it — a department
            // with no lead still records that the rung was reached.
            $table->foreignId('to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('detail');
            $table->timestamp('escalated_at');
            $table->timestamps();

            $table->unique(['task_id', 'trigger', 'level']);
            $table->index(['organization_id', 'escalated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_escalations');
    }
};
