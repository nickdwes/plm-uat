<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whose move it is, tracked on its own — not borrowed from the SLA clock.
 *
 * The clock only pauses when a status with state=pending is set, which is a
 * manual step and only exists once a ticket has a priority to pause against.
 * An untriaged ticket with eleven real messages back and forth had nothing:
 * no clock, no pause history, "0m" everywhere despite an obvious exchange.
 *
 * This tracks the same idea — who needs to act next — independently of
 * whether an SLA target exists, updated automatically by which side sent the
 * last public message, and overridable by hand when the traffic light is
 * wrong (a reply that says "still looking into it" is not a handoff).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            // support | client | vendor
            $table->string('current_owner', 16)->default('support')->after('assigned_to');
            $table->timestamp('owner_set_at')->nullable()->after('current_owner');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn(['current_owner', 'owner_set_at']);
        });
    }
};
