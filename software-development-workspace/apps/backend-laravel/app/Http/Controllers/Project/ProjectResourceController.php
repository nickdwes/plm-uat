<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectPerson;
use App\Models\Task\Task;
use Illuminate\Http\JsonResponse;

/**
 * The resource planning register — for each task, who is needed, how much
 * effort, where (site or remote) and whether it means travel; plus a
 * utilization view that flags anyone booked past their capacity on the
 * project's delivery teams.
 */
class ProjectResourceController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $tasks = Task::where('project_id', $project->id)
            ->with([
                'members.employee:id,first_name,last_name',
                'assignee:id,first_name,last_name',
                'logistics', 'functionalUnit:id,name', 'department:id,name', 'businessUnit:id,name',
            ])
            ->orderBy('position')->orderBy('id')->get();

        $rows = $tasks->map(function (Task $t) {
            $people = collect();
            foreach ($t->members->where('capacity', 'assignee') as $m) {
                if ($m->employee) {
                    $people->push(trim($m->employee->first_name . ' ' . $m->employee->last_name));
                }
            }
            if ($t->assignee) {
                $people->push(trim($t->assignee->first_name . ' ' . $t->assignee->last_name));
            }
            $people = $people->unique()->values();

            $onSite = $t->logistics && ($t->logistics->needs_travel || $t->logistics->needs_client_access);
            $travel = (bool) ($t->logistics?->needs_travel);

            return [
                'task_id'       => $t->id,
                'title'         => $t->title,
                'unit'          => $t->businessUnit?->name ?? $t->department?->name ?? $t->functionalUnit?->name,
                'people'        => $people->all(),
                'people_count'  => $people->count(),
                'effort_days'   => $t->effort_estimate_days !== null ? (float) $t->effort_estimate_days : null,
                'site'          => $onSite ? 'On-site' : 'Remote',
                'location'      => $onSite ? ($t->logistics?->travel_location ?: 'Client site') : null,
                'travel'        => $travel,
            ];
        });

        // Utilization: capacity a person is booked on this project's delivery
        // teams. Over 100% across the project's teams is a staffing conflict.
        $members = ProjectPerson::where('project_id', $project->id)
            ->where('role_kind', ProjectPerson::KIND_MEMBER)
            ->whereNotNull('capacity_percent')
            ->with('employee:id,first_name,last_name')
            ->get();

        $utilization = $members->groupBy('employee_id')->map(function ($group) {
            $e = $group->first()->employee;
            $total = (int) $group->sum('capacity_percent');
            return [
                'name'          => $e ? trim($e->first_name . ' ' . $e->last_name) : '—',
                'total_capacity'=> $total,
                'over_allocated'=> $total > 100,
                'teams'         => $group->count(),
            ];
        })->values();

        return response()->json([
            'data'        => $rows,
            'utilization' => $utilization,
            'summary'     => [
                'total_effort_days' => (float) $rows->sum(fn ($r) => $r['effort_days'] ?? 0),
                'tasks_with_travel' => $rows->where('travel', true)->count(),
                'tasks_on_site'     => $rows->where('site', 'On-site')->count(),
                'people_count'      => $rows->flatMap(fn ($r) => $r['people'])->unique()->count(),
                'over_allocated'    => $utilization->where('over_allocated', true)->count(),
            ],
        ]);
    }
}
