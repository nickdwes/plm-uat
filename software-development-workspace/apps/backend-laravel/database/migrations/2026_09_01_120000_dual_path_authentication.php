<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two ways in, and a record of which one each person has.
 *
 * Everybody in Office 365 signs in through Entra; everybody else needs a local
 * username and a one-time code. Which of those applies is not a preference —
 * it is a fact about the tenant, discovered by asking Graph — so it is stored
 * as the answer to a question that was asked, with the time it was asked.
 *
 * `azure_id` and `sso_only` already existed for the login path
 * (`AzureSsoController` matches on `azure_id`, which is the immutable object
 * id, never the display name). This adds what was missing: the discovery
 * result, the method it implies, and everything the local path needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             * The username for people who have no Office 365 account. Separate
             * from `email`, which stays the address we write to: a contractor
             * may sign in as `j.otieno` and still be reachable at a personal
             * address, and conflating the two makes the address impossible to
             * change without changing the login.
             */
            $table->string('alt_username')->nullable();

            // SSO | LOCAL_TOTP | EMAIL_OTP | UNRESOLVED
            $table->string('auth_method', 16)->default('UNRESOLVED');

            // EMAIL | AUTHENTICATOR
            $table->string('otp_preference', 16)->nullable();

            /*
             * What Graph said, kept apart from what we concluded. "Not found"
             * and "found but disabled" both mean local, but they are different
             * facts and an administrator chasing a problem needs the actual
             * one — as does anyone deciding whether a 403 was a permission
             * problem rather than an answer.
             */
            $table->string('discovery_status', 24)->default('never');
            $table->boolean('account_enabled')->nullable();
            $table->timestamp('last_discovery_at')->nullable();
            $table->text('discovery_error')->nullable();

            /*
             * The shared secret, encrypted at rest, and the recovery codes,
             * hashed like passwords — a recovery code is a password that works
             * once, and storing it readable would defeat the point of hashing
             * the other one.
             *
             * `totp_confirmed_at` is what separates enrolled from enrolling: a
             * secret exists from the moment the QR is shown, and must not
             * authenticate anybody until they have proved they can read it.
             */
            $table->text('totp_secret')->nullable();
            $table->timestamp('totp_confirmed_at')->nullable();
            $table->json('recovery_codes')->nullable();

            $table->index(['auth_method']);
            $table->index(['discovery_status']);
        });

        // A username has to identify one person, and only where it is set.
        \Illuminate\Support\Facades\DB::statement(
            'CREATE UNIQUE INDEX users_alt_username_unique
             ON users (lower(alt_username))
             WHERE alt_username IS NOT NULL',
        );
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::statement('DROP INDEX IF EXISTS users_alt_username_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['auth_method']);
            $table->dropIndex(['discovery_status']);
            $table->dropColumn([
                'alt_username', 'auth_method', 'otp_preference',
                'discovery_status', 'account_enabled', 'last_discovery_at', 'discovery_error',
                'totp_secret', 'totp_confirmed_at', 'recovery_codes',
            ]);
        });
    }
};
