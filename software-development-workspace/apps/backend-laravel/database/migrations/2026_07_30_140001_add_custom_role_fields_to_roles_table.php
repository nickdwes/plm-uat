<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets administrators define roles without letting them define their way to
 * more power than they hold.
 *
 * `excluded_permissions` carves out of a grant rather than requiring every
 * permission to be enumerated — "everything about leave except approving it"
 * is one wildcard and one exclusion. Exclusion always beats inclusion within
 * a role definition.
 *
 * `assignable_scopes` bounds where a definition may ever be used. A role
 * built for departmental use that omits `organization` and `group` cannot be
 * granted tenant-wide later, whatever an administrator intends. Null means
 * unrestricted, which is correct for the built-in roles and should be rare
 * for custom ones.
 *
 * `is_system` protects the built-ins. If `org_hr` can be edited per tenant it
 * stops meaning anything, and the role matrix becomes documentation of
 * something that is no longer true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('scope');
            $table->json('excluded_permissions')->nullable()->after('permissions');
            $table->json('assignable_scopes')->nullable()->after('excluded_permissions');
            $table->foreignId('organization_id')->nullable()->after('assignable_scopes')
                ->constrained('organizations')->cascadeOnDelete();
            $table->text('description')->nullable()->after('display_name');
            $table->foreignId('created_by')->nullable()->after('description')
                ->constrained('users')->nullOnDelete();
        });

        // The four seeded roles are the built-ins the role matrix describes.
        \Illuminate\Support\Facades\DB::table('roles')
            ->whereIn('name', ['group_admin', 'org_hr', 'dept_manager', 'employee'])
            ->update(['is_system' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['created_by']);
            $table->dropColumn([
                'is_system', 'excluded_permissions', 'assignable_scopes',
                'organization_id', 'description', 'created_by',
            ]);
        });
    }
};
