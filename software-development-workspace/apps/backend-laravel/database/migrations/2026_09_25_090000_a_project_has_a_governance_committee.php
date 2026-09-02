<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A project has a governance committee, not four loose slots.
 *
 * The old governance was four `project_people` rows (sponsor / owner /
 * project_manager / engineer) carrying a person and nothing else. This lifts
 * governance into its own table: a fixed committee of roles, each with the
 * person, their responsibility, escalation and approval authority, and a
 * backup — so accountability is defined, not just named.
 *
 * The four existing slots are carried across so nothing set is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_governance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            $table->string('role_key', 60);
            $table->foreignId('assignee_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('backup_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('responsibility')->nullable();
            $table->string('escalation_authority', 500)->nullable();
            $table->string('approval_authority', 500)->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'role_key']);
        });

        // Carry the four old governance slots onto the committee.
        $map = [
            'sponsor'         => 'executive_sponsor',
            'owner'           => 'project_owner',
            'project_manager' => 'project_manager',
            'engineer'        => 'project_engineer',
        ];

        if (Schema::hasTable('project_people')) {
            $rows = DB::table('project_people')->whereIn('role_kind', array_keys($map))->get();
            foreach ($rows as $r) {
                $key = $map[$r->role_kind];
                $exists = DB::table('project_governance')
                    ->where('project_id', $r->project_id)->where('role_key', $key)->exists();
                if ($exists) {
                    continue;
                }
                DB::table('project_governance')->insert([
                    'organization_id'      => $r->organization_id,
                    'project_id'           => $r->project_id,
                    'role_key'             => $key,
                    'assignee_employee_id' => $r->employee_id,
                    'is_mandatory'         => in_array($key, ['executive_sponsor', 'project_manager'], true),
                    'position'             => 0,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_governance');
    }
};
