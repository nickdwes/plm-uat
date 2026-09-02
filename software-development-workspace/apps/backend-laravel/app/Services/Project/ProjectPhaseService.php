<?php

namespace App\Services\Project;

use App\Models\Project\Project;
use App\Models\Project\ProjectPhase;
use App\Models\Task\Task;
use Illuminate\Support\Carbon;

/**
 * Phase status and progress, derived from the tasks under each phase (and its
 * sub-phases) — never stored, the same rule the old PLM used, but in one
 * grouped query instead of an accessor per phase.
 */
class ProjectPhaseService
{
    private const PENDING = ['todo', 'blocked'];
    private const ONGOING = ['in_progress', 'awaiting_approval'];

    /** @return array<int, array> phases (with nested children) carrying status/progress */
    public function forProject(Project $project): array
    {
        $phases = $project->allPhases()->with('children')->get();
        $phaseIds = $phases->pluck('id');

        $byPhaseStatus = Task::whereIn('phase_id', $phaseIds)
            ->selectRaw('phase_id, status, COUNT(*) as n')
            ->groupBy('phase_id', 'status')->get()->groupBy('phase_id');

        $today = Carbon::today()->toDateString();
        $overdue = Task::whereIn('phase_id', $phaseIds)
            ->whereNotNull('deadline')->whereDate('deadline', '<', $today)
            ->where('status', '!=', Task::DONE)
            ->selectRaw('phase_id, COUNT(*) as n')->groupBy('phase_id')
            ->pluck('n', 'phase_id');

        // Roll each phase up over itself plus its children.
        $roots = $phases->whereNull('parent_id')->sortBy('position');
        return $roots->map(function (ProjectPhase $p) use ($phases, $byPhaseStatus, $overdue) {
            $childIds = $phases->where('parent_id', $p->id)->pluck('id')->push($p->id);
            $counts = ['pending' => 0, 'ongoing' => 0, 'completed' => 0, 'all' => 0];
            $od = 0;
            foreach ($childIds as $id) {
                foreach ($byPhaseStatus->get($id, collect()) as $row) {
                    $counts['all'] += (int) $row->n;
                    if (in_array($row->status, self::PENDING, true)) $counts['pending'] += (int) $row->n;
                    elseif (in_array($row->status, self::ONGOING, true)) $counts['ongoing'] += (int) $row->n;
                    elseif ($row->status === Task::DONE) $counts['completed'] += (int) $row->n;
                }
                $od += (int) ($overdue[$id] ?? 0);
            }

            return [
                'id'       => $p->id,
                'name'     => $p->name,
                'position' => $p->position,
                'due_on'   => $p->due_on?->toDateString(),
                'status'   => $this->status($counts),
                'progress' => $counts['all'] > 0 ? (int) round($counts['completed'] / $counts['all'] * 100) : 0,
                'tasks'    => $counts['all'],
                'completed' => $counts['completed'],
                'overdue'  => $od,
                'at_risk'  => $od > 0,
                'children' => $phases->where('parent_id', $p->id)->sortBy('position')->map(fn ($c) => [
                    'id' => $c->id, 'name' => $c->name, 'due_on' => $c->due_on?->toDateString(),
                ])->values(),
            ];
        })->values()->all();
    }

    private function status(array $counts): string
    {
        if ($counts['all'] === 0) return 'pending';
        if ($counts['pending'] === 0 && $counts['ongoing'] === 0) return 'completed';
        if ($counts['ongoing'] > 0 || $counts['completed'] > 0) return 'ongoing';
        return 'pending';
    }
}
