<?php

namespace App\Http\Controllers\Task;

use App\Http\Controllers\Controller;
use App\Models\Task\Task;
use App\Models\Task\TaskDeadlineExtension;
use App\Models\Task\TaskEscalation;
use App\Models\Task\TaskPause;
use App\Support\Authorization\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where the work actually is, and whose fault the waiting was.
 *
 * The board could answer "how many are late". It could not answer the question
 * that follows — late because of whom — because until the responsibility field,
 * the pause ledger and the extension record there was nothing to answer it
 * with. This reads all three.
 *
 * Every figure is derived on request. A task module of this size does not need
 * a reporting table, and a stored total is one that disagrees with the board
 * the first time somebody changes a status by a route that forgot to update it.
 */
class TaskAnalyticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Task::class);

        abort_unless($request->user()?->hasPermission(Permission::TASKS_VIEW), 403,
            'Company-wide figures need company-wide visibility.');

        $filters = $request->validate([
            'from'          => ['nullable', 'date'],
            'to'            => ['nullable', 'date'],
            'project_id'    => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'assigned_to'   => ['nullable', 'integer'],
            'category'      => ['nullable', 'string', 'max:32'],
        ]);

        // Root tasks only. A sub-task is real work but it is counted inside its
        // parent, and mixing the two makes every total quietly wrong.
        $tasks = $this->scope($filters)->get();
        $ids = $tasks->pluck('id');

        return response()->json(['data' => [
            'period'  => ['from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null],
            'health'  => $this->health($tasks),
            'dependency'     => $this->dependency($tasks, $ids),
            'accountability' => $this->accountability($tasks, $ids),
            'escalations'    => $this->escalations($ids),
        ]]);
    }

    /** @return Builder<Task> */
    private function scope(array $f): Builder
    {
        return Task::query()->roots()
            ->with(['activePause', 'pauses'])
            ->withCount('extensions')
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', Carbon::parse($v)->endOfDay()))
            ->when($f['project_id'] ?? null, fn ($q, $v) => $q->where('project_id', $v))
            ->when($f['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when($f['assigned_to'] ?? null, fn ($q, $v) => $q->where('assigned_to', $v))
            ->when($f['category'] ?? null, fn ($q, $v) => $q->where('category', $v));
    }

    /** @param Collection<int, Task> $tasks */
    private function health(Collection $tasks): array
    {
        $open = $tasks->filter(fn (Task $t) => ! $t->is_done);

        return [
            'total'     => $tasks->count(),
            'open'      => $open->count(),
            'closed'    => $tasks->count() - $open->count(),
            // Stopped, waiting on somebody — counted apart from open, because
            // it is open work nobody can move.
            'paused'    => $open->filter(fn (Task $t) => $t->activePause !== null)->count(),
            'extended'  => $tasks->filter(fn (Task $t) => $t->extensions_count > 0)->count(),
            'overdue'   => $open->filter(fn (Task $t) => $t->is_overdue)->count(),
            // Late, and not because anybody else is holding it.
            'overdue_on_us' => $open->filter(fn (Task $t) => $t->is_overdue && $t->activePause === null)->count(),
        ];
    }

    /**
     * Who the open work is sitting with.
     *
     * Two readings of the same question: what people have *said* they are
     * waiting on, and what is actually stopped. They differ, and the gap is
     * itself worth seeing — a task marked "waiting on the client" that nobody
     * paused is a claim, not a record.
     *
     * @param Collection<int, Task> $tasks
     */
    private function dependency(Collection $tasks, Collection $ids): array
    {
        $open = $tasks->filter(fn (Task $t) => ! $t->is_done);

        $byParty = collect(Task::RESPONSIBILITIES)->map(function (string $party) use ($open) {
            $mine = $open->where('responsibility', $party);
            $overdue = $mine->filter(fn (Task $t) => $t->is_overdue);

            return [
                'party'   => $party,
                'open'    => $mine->count(),
                'overdue' => $overdue->count(),
                // The number that answers "and how long have they had it".
                'avg_days_overdue' => $overdue->isEmpty()
                    ? null
                    : round($overdue->avg(fn (Task $t) => $t->days_overdue ?? 0), 1),
                'paused_days' => round($mine->sum(fn (Task $t) => $t->paused_days), 1),
            ];
        })->filter(fn (array $r) => $r['open'] > 0)->sortByDesc('open')->values();

        $unsaid = $open->whereNull('responsibility')->count();

        $pauses = TaskPause::whereIn('task_id', $ids)->whereNull('resumed_at')->get();

        return [
            'by_responsibility' => $byParty->all(),
            // Open work nobody has said anything about. Not zero-rated: an
            // unanswered question is not the same as "us".
            'unattributed' => $unsaid,
            'open_pauses'  => collect(TaskPause::REASONS)->map(fn (array $spec, string $category) => [
                'category' => $category,
                'label'    => $spec['label'],
                'count'    => $pauses->where('category', $category)->count(),
                'days'     => round($pauses->where('category', $category)->sum(fn (TaskPause $p) => $p->minutes()) / 1440, 1),
            ])->filter(fn (array $r) => $r['count'] > 0)->sortByDesc('days')->values()->all(),
        ];
    }

    /** @param Collection<int, Task> $tasks */
    private function accountability(Collection $tasks, Collection $ids): array
    {
        $pauses = TaskPause::whereIn('task_id', $ids)->get();
        $extensions = TaskDeadlineExtension::whereIn('task_id', $ids)->get();

        return [
            // Where the time actually goes, which is not always where people
            // think it does.
            'bottlenecks' => $pauses->groupBy('reason')
                ->map(fn (Collection $g, string $reason) => [
                    'reason'   => $reason,
                    'category' => $g->first()->category,
                    'count'    => $g->count(),
                    'days'     => round($g->sum(fn (TaskPause $p) => $p->minutes()) / 1440, 1),
                ])
                ->sortByDesc('days')->values()->take(8)->all(),

            'extensions' => [
                'total'          => $extensions->count(),
                'tasks_affected' => $extensions->pluck('task_id')->unique()->count(),
                'avg_days_moved' => $extensions->isEmpty() ? null
                    : round($extensions->filter(fn ($e) => $e->daysMoved() !== null)->avg(fn ($e) => $e->daysMoved()) ?? 0, 1),
                // Oldest first, so a trend reads left to right.
                'by_month' => $extensions->groupBy(fn ($e) => $e->created_at->format('Y-m'))
                    ->map(fn (Collection $g, string $month) => ['month' => $month, 'count' => $g->count()])
                    ->sortBy('month')->values()->all(),
                'most_moved' => $tasks->filter(fn (Task $t) => $t->extensions_count >= 2)
                    ->sortByDesc('extensions_count')->take(8)
                    ->map(fn (Task $t) => [
                        'id' => $t->id, 'reference' => $t->reference, 'title' => $t->title,
                        'moves' => $t->extensions_count, 'deadline' => $t->deadline?->toDateString(),
                    ])->values()->all(),
            ],

            'punctuality' => $this->punctuality($tasks),
        ];
    }

    /**
     * Did it land on time — and would it have, without the waiting?
     *
     * There is no SLA on tasks, so this is not an SLA figure and does not
     * pretend to be one. It is the honest equivalent: what was finished against
     * the date it was finally due. The second number subtracts the days the
     * task spent parked on somebody else, which is the difference between a
     * team that is slow and a team that is blocked.
     *
     * @param Collection<int, Task> $tasks
     */
    private function punctuality(Collection $tasks): array
    {
        $judged = $tasks->filter(fn (Task $t) => $t->is_done && $t->completed_at && $t->deadline);

        if ($judged->isEmpty()) {
            return ['finished' => 0, 'on_time' => 0, 'late' => 0, 'rate' => null, 'rate_net_of_waiting' => null];
        }

        $onTime = $judged->filter(fn (Task $t) => $t->completed_at->startOfDay()->lte($t->deadline));

        $onTimeNet = $judged->filter(function (Task $t) {
            $lateBy = $t->deadline->startOfDay()->diffInDays($t->completed_at->startOfDay(), false);

            return $lateBy <= $t->paused_days;
        });

        return [
            'finished' => $judged->count(),
            'on_time'  => $onTime->count(),
            'late'     => $judged->count() - $onTime->count(),
            'rate'     => round($onTime->count() / $judged->count() * 100, 1),
            'rate_net_of_waiting' => round($onTimeNet->count() / $judged->count() * 100, 1),
        ];
    }

    /**
     * Who has been told, and how often nobody could be.
     *
     * `unreachable` is the one that matters: a rung reached with nobody in the
     * role is an escalation that went nowhere, and counting them is the only
     * way anyone finds out the ladder has a hole in it.
     */
    private function escalations(Collection $ids): array
    {
        $rows = TaskEscalation::whereIn('task_id', $ids)->get();

        return [
            'total'       => $rows->count(),
            'tasks'       => $rows->pluck('task_id')->unique()->count(),
            'unreachable' => $rows->whereNull('to_employee_id')->count(),
            'by_trigger'  => collect(TaskEscalation::TRIGGERS)->map(fn (string $label, string $key) => [
                'trigger' => $key, 'label' => $label, 'count' => $rows->where('trigger', $key)->count(),
            ])->filter(fn (array $r) => $r['count'] > 0)->values()->all(),
            'by_level' => collect(TaskEscalation::LEVELS)->map(fn (string $label, string $key) => [
                'level' => $key, 'label' => $label,
                'count' => $rows->where('level', $key)->count(),
                'unreachable' => $rows->where('level', $key)->whereNull('to_employee_id')->count(),
            ])->filter(fn (array $r) => $r['count'] > 0)->values()->all(),
        ];
    }
}
