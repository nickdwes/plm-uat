<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A document class above "private to the employee file": disciplinary
     * records, investigation notes, medical letters. A default document keeps
     * following its parent employee record; a confidential one additionally
     * needs `documents.view_confidential` — including against the employee it
     * is about, which is the whole point of the flag.
     *
     * The same migration seeds the permission into live `org_hr` so HR keeps
     * the access it has today; narrowing individual HR people is what the
     * role editor's clone-and-exclude flow is for.
     */
    public function up(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            $table->boolean('is_confidential')->default(false)->after('category');
        });

        $this->grant('org_hr', 'documents.view_confidential');
        $this->grant('account_manager', 'accounts.view_contacts');
    }

    public function down(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            $table->dropColumn('is_confidential');
        });
    }

    /** Append a permission to a live role, idempotently. */
    private function grant(string $roleName, string $permission): void
    {
        $role = DB::table('roles')->where('name', $roleName)->first();
        if (! $role) {
            return;
        }

        $permissions = json_decode($role->permissions ?? '[]', true) ?: [];
        if (in_array($permission, $permissions, true)) {
            return;
        }

        $permissions[] = $permission;
        DB::table('roles')->where('id', $role->id)->update([
            'permissions' => json_encode(array_values($permissions)),
            'updated_at'  => now(),
        ]);
    }
};
