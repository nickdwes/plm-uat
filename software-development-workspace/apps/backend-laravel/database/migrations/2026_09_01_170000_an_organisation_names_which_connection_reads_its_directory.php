<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which app registration is allowed to read the directory.
 *
 * Mirrors `calendar_connection_id` deliberately: one explicit pointer per job,
 * never a guess between several. Discovery had been taking the first active
 * Microsoft connection it found, which was fine while there was one and wrong
 * the moment there were two.
 *
 * Separate from the calendar connection because the permissions are of a
 * different order. Reading the directory means `User.Read.All`, and the work
 * after it means `UserAuthenticationMethod.ReadWrite.All` — the ability to
 * change how people authenticate. Granting those to the registration that also
 * reads every mailbox makes one leaked secret reach directory data, mail, and
 * the authentication methods themselves. Two registrations means a compromise
 * of one is not a compromise of all three.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->foreignId('identity_connection_id')->nullable()
                ->after('calendar_connection_id')
                ->constrained('integration_connections')
                // The pointer goes, the organisation stays: losing a connection
                // must not take the tenant with it.
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('identity_connection_id');
        });
    }
};
