<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the account lifecycle state that authorization gates on.
 *
 * Without it there is no way to stop a leaver: revoking access means deleting
 * their role assignments, which destroys the record of what they could do —
 * exactly the thing an audit asks about. With it, a disabled principal is
 * denied everything while their assignments and their attribution on past
 * records both survive.
 *
 *   pending    created, cannot sign in yet (pre-hire, preboarding)
 *   active     normal
 *   suspended  temporarily blocked, expected to return
 *   disabled   left the organisation
 *
 * Existing rows default to active — they are, by definition, in use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_status', 20)->default('active')->after('sso_only');
            $table->timestamp('status_changed_at')->nullable()->after('account_status');
            $table->index('account_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['account_status']);
            $table->dropColumn(['account_status', 'status_changed_at']);
        });
    }
};
