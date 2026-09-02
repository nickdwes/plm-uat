<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One published weekly report for a project. Immutable history: each row is the
 * project as it stood the week it was published, so week-over-week is a real
 * comparison of two recorded rows, never a live recompute.
 */
class ProjectWeeklySnapshot extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_weekly_snapshots';

    public const RAG_GREEN = 'green';
    public const RAG_AMBER = 'amber';
    public const RAG_RED   = 'red';

    protected $fillable = [
        'organization_id', 'project_id', 'week_start', 'rag',
        'progress_pct', 'completed_tasks', 'total_tasks', 'overdue_tasks',
        'open_issues', 'open_dependencies', 'summary', 'notes', 'created_by',
    ];

    protected $casts = [
        'week_start'   => 'date',
        'notes'        => 'array',
        'progress_pct' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** ≥90 green, 60–89 amber, below red — the report's headline colour. */
    public static function ragFor(int $progressPct, int $overdue): string
    {
        if ($overdue > 0 && $progressPct < 90) {
            // An overdue task caps an otherwise-amber week at red once behind.
            return $progressPct < 60 ? self::RAG_RED : self::RAG_AMBER;
        }
        if ($progressPct >= 90) return self::RAG_GREEN;
        if ($progressPct >= 60) return self::RAG_AMBER;
        return self::RAG_RED;
    }
}
