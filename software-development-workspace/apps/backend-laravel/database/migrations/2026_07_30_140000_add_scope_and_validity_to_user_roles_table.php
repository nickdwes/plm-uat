<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns a role assignment into a first-class, auditable, time-bound grant.
 *
 * An assignment was previously just (user, role, organization) — true while
 * the row existed, false once deleted. That makes three things impossible:
 *
 *   - expressing reach. A role granting `leave.view` said nothing about
 *     whose leave, so scope had to be encoded in the permission string.
 *   - expiring a grant. A mover keeps their old department's roles until
 *     somebody remembers to delete the row.
 *   - answering "who had access last March". A deleted row has no history.
 *
 * Revocation is therefore recorded, never destructive. Rows are retained and
 * marked; `revoked_at` is what makes a grant inert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_roles', function (Blueprint $table) {
            // How far this grant reaches. See Support\Authorization\Scope.
            $table->string('scope', 20)->default('organization')->after('organization_id');

            // Validity window. valid_until null means indefinite.
            $table->timestamp('valid_from')->nullable()->after('scope');
            $table->timestamp('valid_until')->nullable()->after('valid_from');

            // Revocation is recorded rather than deleted, so the grant remains
            // answerable after it stops being effective.
            $table->timestamp('revoked_at')->nullable()->after('valid_until');
            $table->foreignId('revoked_by')->nullable()->after('revoked_at')
                ->constrained('users')->nullOnDelete();

            $table->foreignId('granted_by')->nullable()->after('revoked_by')
                ->constrained('users')->nullOnDelete();

            $table->string('grant_reason')->nullable()->after('granted_by');

            $table->index(['user_id', 'revoked_at']);
            $table->index('valid_until');
        });

        // Existing rows: organization_id null has always meant group-wide,
        // anything else organization-wide. Preserve that exactly — this
        // migration must not change who can do what.
        DB::table('user_roles')->whereNull('organization_id')->update(['scope' => 'group']);
        DB::table('user_roles')->whereNotNull('organization_id')->update(['scope' => 'organization']);

        // Pre-existing grants are effective from before any of this existed.
        DB::table('user_roles')->whereNull('valid_from')->update(['valid_from' => now()->subYear()]);
    }

    public function down(): void
    {
        Schema::table('user_roles', function (Blueprint $table) {
            $table->dropForeign(['revoked_by']);
            $table->dropForeign(['granted_by']);
            $table->dropIndex(['user_id', 'revoked_at']);
            $table->dropIndex(['valid_until']);
            $table->dropColumn([
                'scope', 'valid_from', 'valid_until',
                'revoked_at', 'revoked_by', 'granted_by', 'grant_reason',
            ]);
        });
    }
};
