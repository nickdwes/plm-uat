<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RBAC: roles are organization-scoped (not global enums)
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');             // group_admin|org_hr|dept_manager|employee
            $table->string('display_name');
            $table->string('scope');            // group|organization
            $table->json('permissions')->nullable();
            $table->timestamps();

            $table->unique('name');
        });

        // Assign a role to a user, scoped to an organization (null org_id = group-wide)
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role_id', 'organization_id'], 'user_role_org_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('roles');
    }
};
