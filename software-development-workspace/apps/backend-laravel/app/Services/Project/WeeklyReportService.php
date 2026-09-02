<?php

namespace App\Services\Project;

use App\Models\Project\Project;
use App\Models\Project\ProjectDependency;
use App\Models\Project\ProjectIssue;
use App\Models\Project\ProjectRisk;
use App\Models\Project\ProjectWeeklySnapshot;
use App\Models\Task\Task;
use Illuminate\Support\Carbon;

/**
 * The weekly report. Computes the live picture for a given week, and publishes
 * it as an immutable snapshot so week-over-week history is recorded, not
 * reconstructed — the fix for the old PLM's "last week == this week" bug.
 */
class WeeklyReportService
{
    /** Monday of the week containing $date (defaults to today). */
    public function weekStart(?Carbon $date = null): Carbon
    {
        return ($date ?? Carbon::today())->copy()->startOfWeek(Carbon::MONDAY);
    }

    /**
     * The live report for the current week: computed figures, the OKR tasks,
     * open risks/dependencies/issues, at-risk milestones, and last week's
     * published snapshot for a real comparison.
     */
    public function current(Project $project): array
    {
        $weekStart = $this->weekStart();
        $today     = Carbon::today()->toDateString();

        $tasks = $project->tasks()->get(['id', 'title', 'status', 'deadline', 'weekly_okr']);
        $total     = $tasks->count();
        $completed = $tasks->where('status', Task::DONE)->count();
        $overdue   = $tasks->filter(fn ($t) =>
            $t->status !== Task::DONE && $t->deadline && $t->deadline->toDateString() < $today
        )->count();

        $progress = $total > 0 ? (int) round($completed / $total * 100) : 0;
        $rag      = ProjectWeeklySnapshot::ragFor($progress, $overdue);

        $openIssues = $project->issues()
            ->where('status', '!=', ProjectIssue::STATUS_RESOLVED)->count();
        $openDeps = $project->dependencies()
            ->where('status', '!=', ProjectDependency::STATUS_RESOLVED)->count();

        // OKRs — tasks a PM raised as weekly objectives, with where they stand.
        $okrs = $tasks->where('weekly_okr', true)->map(fn ($t) => [
            'id'     => $t->id,
            'title'  => $t->title,
            'status' => $t->status,
            'done'   => $t->status === Task::DONE,
        ])->values();

        // Key risks — open/mitigating, hottest first.
        $risks = $project->risks()
            ->where('status', '!=', ProjectRisk::STATUS_CLOSED)
            ->get(['id', 'title', 'likelihood', 'impact', 'status'])
            ->sortByDesc(fn (ProjectRisk $r) => $r->score())
            ->take(5)
            ->map(fn (ProjectRisk $r) => [
                'id' => $r->id, 'title' => $r->title, 'score' => $r->score(), 'status' => $r->status,
            ])->values();

        $lastWeek = $project->weeklySnapshots()
            ->where('week_start', '<', $weekStart->toDateString())
            ->orderByDesc('week_start')->first();

        return [
            'week_start' => $weekStart->toDateString(),
            'published'  => $project->weeklySnapshots()
                ->where('week_start', $weekStart->toDateString())->exists(),
            'live' => [
                'rag'               => $rag,
                'progress_pct'      => $progress,
                'completed_tasks'   => $completed,
                'total_tasks'       => $total,
                'overdue_tasks'     => $overdue,
                'open_issues'       => $openIssues,
                'open_dependencies' => $openDeps,
                'okrs'              => $okrs,
                'risks'             => $risks,
            ],
            'last_week'  => $lastWeek ? $this->payload($lastWeek) : null,
            'deltas'     => $lastWeek ? [
                'progress_pct' => $progress - (int) $lastWeek->progress_pct,
                'rag_changed'  => $rag !== $lastWeek->rag,
                'rag_was'      => $lastWeek->rag,
            ] : null,
        ];
    }

    /**
     * Publish this week: write (or overwrite) the snapshot for the current week
     * and stamp the project's latest RAG. Idempotent per week.
     */
    public function publish(Project $project, ?string $summary, ?int $userId): ProjectWeeklySnapshot
    {
        $c = $this->current($project)['live'];
        $weekStart = $this->weekStart()->toDateString();

        $snapshot = ProjectWeeklySnapshot::updateOrCreate(
            ['project_id' => $project->id, 'week_start' => $weekStart],
            [
                'organization_id'   => $project->organization_id,
                'rag'               => $c['rag'],
                'progress_pct'      => $c['progress_pct'],
                'completed_tasks'   => $c['completed_tasks'],
                'total_tasks'       => $c['total_tasks'],
                'overdue_tasks'     => $c['overdue_tasks'],
                'open_issues'       => $c['open_issues'],
                'open_dependencies' => $c['open_dependencies'],
                'summary'           => $summary,
                'notes'             => ['okrs' => $c['okrs'], 'risks' => $c['risks']],
                'created_by'        => $userId,
            ],
        );

        // The latest published RAG lives on the project for lists/dashboard.
        $project->forceFill(['rag' => $c['rag']])->save();

        return $snapshot;
    }

    /** History, newest first. */
    public function history(Project $project): array
    {
        return $project->weeklySnapshots()->with('creator:id,name')->get()
            ->map(fn (ProjectWeeklySnapshot $s) => $this->payload($s))->all();
    }

    public function payload(ProjectWeeklySnapshot $s): array
    {
        return [
            'id'                => $s->id,
            'week_start'        => $s->week_start?->toDateString(),
            'rag'               => $s->rag,
            'progress_pct'      => (int) $s->progress_pct,
            'completed_tasks'   => (int) $s->completed_tasks,
            'total_tasks'       => (int) $s->total_tasks,
            'overdue_tasks'     => (int) $s->overdue_tasks,
            'open_issues'       => (int) $s->open_issues,
            'open_dependencies' => (int) $s->open_dependencies,
            'summary'           => $s->summary,
            'okrs'              => $s->notes['okrs'] ?? [],
            'risks'             => $s->notes['risks'] ?? [],
            'published_by'      => $s->relationLoaded('creator') ? $s->creator?->name : null,
            'published_at'      => $s->created_at?->toDateTimeString(),
        ];
    }
}
