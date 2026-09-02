<?php

use App\Support\Project\PmActivityDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The standard PM activity checklist becomes editable.
 *
 * "Generate PM activities" seeded a hardcoded house checklist. This lifts that
 * list into a table each organisation owns and can define for itself — the same
 * activities to start, now editable, addable and removable from a settings
 * page, with the generator reading from here instead of the constant.
 *
 * Seeded from the existing PmActivityDefaults so nothing changes on day one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pm_task_definitions')) {
            Schema::create('pm_task_definitions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->string('phase')->nullable();          // matches a ProjectPhase name
                $table->string('title');
                $table->string('priority', 16)->default('medium');
                $table->boolean('weekly_okr')->default(false);
                $table->unsignedInteger('position')->default(0);
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['organization_id', 'is_active']);
            });
        }

        // Seed every organisation from the house defaults, in lifecycle order.
        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            $position = 0;
            foreach (PmActivityDefaults::map() as $phase => $titles) {
                foreach ($titles as $title) {
                    $exists = DB::table('pm_task_definitions')
                        ->where('organization_id', $orgId)
                        ->where('phase', $phase)
                        ->whereRaw('lower(title) = ?', [strtolower($title)])
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('pm_task_definitions')->insert([
                        'organization_id' => $orgId,
                        'phase'           => $phase,
                        'title'           => $title,
                        'priority'        => 'medium',
                        'weekly_okr'      => str_contains(strtolower($title), 'weekly'),
                        'position'        => $position++,
                        'is_active'       => true,
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_task_definitions');
    }
};
