<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One explicit pointer, not a guess.
 *
 * TeamsService used to read its own MICROSOFT_TENANT_ID/CLIENT_ID/CLIENT_SECRET
 * env vars — a credential path nothing else in the app used, disconnected from
 * `integration_connections` entirely. That was fine while there was at most
 * one Microsoft app in the picture; it stops being fine the moment an org has
 * several connections for different purposes (mail, calendar, asset sync,
 * provisioning) and something has to say which one creates Teams meetings.
 *
 * A boolean flag on `integration_connections` would only say "this connection
 * may be used for calendars" — still leaving "which one, if there's more than
 * one" unanswered. This says it outright.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->foreignId('calendar_connection_id')->nullable()
                ->after('settings')
                ->constrained('integration_connections')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('calendar_connection_id');
        });
    }
};
