<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is on a project, and in what role.
 *
 * The old PLM spread this across a role-bucket table, an assignment table and
 * two M:N join tables for managers and leads. This unifies it: `project_roles`
 * are the named buckets (Project Management, Tech Lead, Implementation
 * Engineers, Presales — the old default trio plus Presales, which the old
 * system made you add by hand), and `project_people` is one assignment table
 * carrying a role_kind so a manager, a lead and a role member all live in the
 * same place. The single `projects.project_lead_id` becomes one of these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'position']);
        });

        Schema::create('project_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_role_id')->nullable()->constrained('project_roles')->nullOnDelete();
            $table->string('role_kind', 16)->default('member'); // manager | lead | member
            $table->timestamps();

            $table->unique(['project_id', 'employee_id', 'role_kind', 'project_role_id'], 'project_people_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_people');
        Schema::dropIfExists('project_roles');
    }
};
