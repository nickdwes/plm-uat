<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a one-time code needs to actually be one-time.
 *
 * TOTP on its own is not single-use: the same six digits stay valid for the
 * whole thirty-second step, and with the ±1 window a shoulder-surfed or
 * phished code is good for up to ninety seconds. Recording the step a code was
 * accepted at, and refusing anything at or below it, is what makes "one-time"
 * true rather than aspirational.
 *
 * The failure counter is the other half. Six digits is a million possibilities,
 * which is only expensive if the attacker is made to wait; unthrottled, a
 * script walks the space in minutes. Both live on the row rather than in the
 * cache so a restart does not hand an attacker a clean slate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The last accepted time-step counter. Anything at or below it has
            // already been spent.
            $table->unsignedBigInteger('totp_last_step')->nullable();

            $table->unsignedSmallInteger('totp_failures')->default(0);
            $table->timestamp('totp_locked_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['totp_last_step', 'totp_failures', 'totp_locked_until']);
        });
    }
};
