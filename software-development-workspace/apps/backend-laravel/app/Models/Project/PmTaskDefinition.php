<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use App\Support\Project\PmActivityDefaults;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One standard project-management activity an organisation seeds new projects
 * with — the editable form of the old hardcoded checklist. "Generate PM
 * activities" turns each active definition into a task on the project.
 */
class PmTaskDefinition extends Model
{
    use BelongsToOrganization;

    protected $table = 'pm_task_definitions';

    protected $fillable = [
        'organization_id', 'phase', 'title', 'priority',
        'weekly_okr', 'position', 'is_active', 'created_by',
    ];

    protected $casts = [
        'weekly_okr' => 'boolean',
        'is_active'  => 'boolean',
        'position'   => 'integer',
    ];

    protected $attributes = [
        'priority'   => 'medium',
        'weekly_okr' => false,
        'is_active'  => true,
        'position'   => 0,
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Back-fill an organisation's checklist from the house defaults the first
     * time it is asked for — so a tenant created after the migration, or one
     * that cleared its list and wants it back, is never simply empty.
     */
    public static function ensureSeeded(int $organizationId): void
    {
        if (static::withoutGlobalScopes()->where('organization_id', $organizationId)->exists()) {
            return;
        }

        $position = 0;
        $rows = [];
        foreach (PmActivityDefaults::map() as $phase => $titles) {
            foreach ($titles as $title) {
                $rows[] = [
                    'organization_id' => $organizationId,
                    'phase'           => $phase,
                    'title'           => $title,
                    'priority'        => 'medium',
                    'weekly_okr'      => str_contains(strtolower($title), 'weekly'),
                    'position'        => $position++,
                    'is_active'       => true,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ];
            }
        }

        if ($rows) {
            DB::table('pm_task_definitions')->insert($rows);
        }
    }
}
