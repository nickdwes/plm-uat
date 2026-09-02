<?php

namespace App\Services\Project;

use App\Models\Project\Project;
use App\Models\Task\Task;
use Illuminate\Support\Carbon;

/**
 * Consolidates a project's task-level travel into cost-effective cycles.
 *
 * Travel is flagged per task; tasks at the same site whose dates overlap are
 * merged into one journey, so a team travels once for several tasks. Shared by
 * the travel schedule and the travel-request register.
 */
class TravelCycleBuilder
{
    /** Round-trip travel days assumed per mode. */
    private const TRAVEL_DAYS = ['air' => 2, 'inter_city' => 2, 'road' => 2, 'local' => 0];

    /** @return array<int, array<string, mixed>> */
    public function build(Project $project): array
    {
        $tasks = Task::where('project_id', $project->id)
            ->whereHas('logistics', fn ($l) => $l->where('needs_travel', true))
            ->with(['logistics', 'members.employee:id,first_name,last_name', 'assignee:id,first_name,last_name'])
            ->get();

        $cycles = [];
        $number = 1;

        $byLocation = $tasks->groupBy(fn (Task $t) => strtolower(trim($t->logistics->travel_location ?: 'Unspecified')));

        foreach ($byLocation as $group) {
            $sorted = $group->sortBy(fn (Task $t) => optional($t->started_on)->toDateString() ?? '9999-12-31')->values();

            $open = null;
            foreach ($sorted as $task) {
                $start = $task->started_on;
                $end   = $task->deadline ?? $task->started_on;

                $overlaps = $open && $start && $open['end']
                    && Carbon::parse($start)->lte(Carbon::parse($open['end']));

                if ($open && (! $start || ! $open['end'] || $overlaps)) {
                    $this->addTask($open, $task, $end);
                } else {
                    if ($open) {
                        $cycles[] = $this->finish($open, $number++);
                    }
                    $open = $this->begin($task, $start, $end);
                }
            }
            if ($open) {
                $cycles[] = $this->finish($open, $number++);
            }
        }

        return $cycles;
    }

    private function begin(Task $task, $start, $end): array
    {
        $cycle = [
            'location' => $task->logistics->travel_location ?: 'Unspecified',
            'tasks'    => [], 'team' => [], 'modes' => [],
            'start' => null, 'end' => null, 'accommodation' => false,
        ];
        $this->addTask($cycle, $task, $end);
        if ($start) {
            $cycle['start'] = Carbon::parse($start)->toDateString();
        }

        return $cycle;
    }

    private function addTask(array &$cycle, Task $task, $end): void
    {
        $cycle['tasks'][] = ['id' => $task->id, 'title' => $task->title];

        foreach ($task->members as $m) {
            if ($m->employee) {
                $cycle['team'][$m->employee->id] = trim($m->employee->first_name . ' ' . $m->employee->last_name);
            }
        }
        if ($task->assignee) {
            $cycle['team'][$task->assignee->id] = trim($task->assignee->first_name . ' ' . $task->assignee->last_name);
        }

        if ($task->logistics->travel_mode) {
            $cycle['modes'][$task->logistics->travel_mode] = true;
        }
        if ($task->logistics->needs_accommodation) {
            $cycle['accommodation'] = true;
        }

        $endStr = $end ? Carbon::parse($end)->toDateString() : null;
        if ($endStr && (! $cycle['end'] || $endStr > $cycle['end'])) {
            $cycle['end'] = $endStr;
        }
    }

    private function finish(array $cycle, int $number): array
    {
        $duration = ($cycle['start'] && $cycle['end'])
            ? Carbon::parse($cycle['start'])->diffInDays(Carbon::parse($cycle['end'])) + 1
            : null;

        $modes = array_keys($cycle['modes']);
        $travelDays = 0;
        foreach ($modes as $mode) {
            $travelDays = max($travelDays, self::TRAVEL_DAYS[$mode] ?? 1);
        }
        if (! $modes) {
            $travelDays = null;
        }

        return [
            'number'        => $number,
            'key'           => md5(strtolower($cycle['location']) . '|' . ($cycle['start'] ?? '')),
            'location'      => $cycle['location'],
            'tasks'         => $cycle['tasks'],
            'team'          => array_values($cycle['team']),
            'start'         => $cycle['start'],
            'end'           => $cycle['end'],
            'duration_days' => $duration,
            'travel_days'   => $travelDays,
            'modes'         => $modes,
            'accommodation' => $cycle['accommodation'],
        ];
    }
}
