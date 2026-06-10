<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Panelist scoring token for public form access
        Schema::table('interview_panelists', function (Blueprint $table) {
            $table->string('scoring_token', 64)->nullable()->unique()->after('confirmed');
            $table->timestamp('scoring_token_expires_at')->nullable()->after('scoring_token');
        });

        // Teams calendar event + candidate schedule tracking
        Schema::table('interviews', function (Blueprint $table) {
            $table->string('teams_event_id')->nullable()->after('recommendation');
            $table->text('teams_join_url')->nullable()->after('teams_event_id');
            $table->boolean('candidate_invite_sent')->default(false)->after('teams_join_url');
            $table->timestamp('candidate_invite_sent_at')->nullable()->after('candidate_invite_sent');
        });
    }

    public function down(): void
    {
        Schema::table('interview_panelists', function (Blueprint $table) {
            $table->dropColumn(['scoring_token', 'scoring_token_expires_at']);
        });
        Schema::table('interviews', function (Blueprint $table) {
            $table->dropColumn(['teams_event_id', 'teams_join_url', 'candidate_invite_sent', 'candidate_invite_sent_at']);
        });
    }
};
