<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asking people to close their day, and telling somebody when they do not.
 *
 * The commitment record makes communication possible. It does not make it
 * happen. Left as it is, the unexplained count grows quietly until a manager
 * thinks to look — which is the same failure as before with better bookkeeping,
 * because the person who could have explained it in ten seconds was never
 * asked.
 *
 * Two stamps, because there are two different moments and conflating them would
 * make the second unrepeatable after the first:
 *
 *   `reminded_at` — the nudge to the person, at the end of their own working
 *   day. Anybody can forget to close out; being asked once, on the day, while
 *   they still remember why, is the whole intervention.
 *
 *   `escalated_at` — telling the manager, the following morning, about what
 *   stayed unanswered. Deliberately a day later and never on the same day: a
 *   manager told at five o'clock that something is not finished is being told
 *   about the ordinary state of ordinary work.
 *
 * Both are stamps rather than flags so the record says when, and so a run that
 * fails halfway leaves nothing double-sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('notes');
            $table->timestamp('escalated_at')->nullable()->after('reminded_at');

            /*
             * The sweep asks "which days are over and still unanswered", across
             * every organisation, every hour. Without this it reads the table.
             */
            $table->index(['work_date', 'reminded_at']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->dropIndex(['work_date', 'reminded_at']);
            $table->dropColumn(['reminded_at', 'escalated_at']);
        });
    }
};
