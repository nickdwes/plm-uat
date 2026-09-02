<?php

namespace App\Services\Task;

use App\Models\Project\ProjectPerson;
use App\Models\Task\Task;
use App\Models\Task\TaskEscalation;
use App\Models\Task\TaskPause;
use Illuminate\Support\Collection;

/**
 * Late work climbing somebody's desk.
 *
 * Overdue tasks were visible on a board and nowhere else, which means they were
 * visible to whoever already knew. This walks them and tells the next person up
 * — once per rung, because an escalation that re-fires nightly is one people
 * filter into a folder, and then the ladder is decoration.
 *
 * ── A paused task does not climb the overdue ladder ──
 *
 * Waiting on a client's signature is not the team failing, and charging that
 * time to them is how a governance report ends up arguing the opposite of what
 * happened. A pause has its own ladder, and it starts when the date somebody
 * promised has passed.
 */
class TaskEscalationLadder
{
    /**
     * Days past the mark before each rung is reached.
     *
     * Defaults rather than settings. Making them configurable before anybody
     * has lived with them is how a system acquires a settings page nobody knows
     * how to fill in; the numbers are here, named, and easy to move.
     */
    public const LADDER = [
        TaskEscalation::TRIGGER_OVERDUE => [
            1  => TaskEscalation::LEVEL_OWNER,
            3  => TaskEscalation::LEVEL_PM,
            7  => TaskEscalation::LEVEL_DEPARTMENT,
            14 => TaskEscalation::LEVEL_SPONSOR,
        ],
        TaskEscalation::TRIGGER_PAUSE_OVERRUN => [
            1  => TaskEscalation::LEVEL_OWNER,
            5  => TaskEscalation::LEVEL_PM,
            10 => TaskEscalation::LEVEL_DEPARTMENT,
        ],
        // Counted in slips, not days: three moves is a plan that is not holding.
        TaskEscalation::TRIGGER_REPEAT_SLIPS => [
            3 => TaskEscalation::LEVEL_PM,
            5 => TaskEscalation::LEVEL_DEPARTMENT,
        ],
    ];

    public function __construct(private TaskNotifier $notifier)
    {
    }

    /**
     * Walk everything that might have gone past a mark.
     *
     * `$quietly` records the rungs without telling anybody. That is for the
     * first run against a system that already has a backlog: a task 200 days
     * overdue is a real problem, but it did not become one last night, and
     * paging four people about each of several hundred of them at once is how
     * an escalation ladder is switched off in its first week. Seeded once, it
     * then speaks only about movement.
     *
     * @return array{escalated: int, considered: int}
     */
    public function sweep(?int $organizationId = null, bool $dryRun = false, bool $quietly = false): array
    {
        $tasks = Task::query()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->open()
            ->with(['assignee', 'project', 'activePause', 'extensions', 'escalations'])
            ->get();

        $fired = 0;

        foreach ($tasks as $task) {
            foreach ($this->due($task) as [$trigger, $level, $detail]) {
                if (! $dryRun) {
                    $this->climb($task, $trigger, $level, $detail, $quietly);
                }
                $fired++;
            }
        }

        return ['escalated' => $fired, 'considered' => $tasks->count()];
    }

    /**
     * Every rung this task has reached and not yet climbed.
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    public function due(Task $task): array
    {
        $already = $task->relationLoaded('escalations') ? $task->escalations : $task->escalations()->get();
        $done = $already->map(fn (TaskEscalation $e) => $e->trigger . ':' . $e->level)->all();

        $rungs = [];

        foreach ($this->reached($task) as [$trigger, $measure, $detail]) {
            foreach (self::LADDER[$trigger] as $threshold => $level) {
                if ($measure >= $threshold && ! in_array($trigger . ':' . $level, $done, true)) {
                    $rungs[] = [$trigger, $level, $detail];
                }
            }
        }

        return $rungs;
    }

    /**
     * What this task has gone past, and by how much.
     *
     * @return list<array{0:string,1:int|float,2:string}>
     */
    private function reached(Task $task): array
    {
        $out = [];

        $pause = $task->relationLoaded('activePause') ? $task->activePause : $task->activePause()->first();

        // Past its deadline — but only if the delay is ours. A task sitting
        // with a client is late, and it is not late because of us.
        if ($task->deadline && $task->deadline->isPast() && ! $pause) {
            $days = (int) $task->deadline->startOfDay()->diffInDays(now()->startOfDay());
            $out[] = [TaskEscalation::TRIGGER_OVERDUE, $days, "{$days} day(s) past its deadline of {$task->deadline->toDateString()}."];
        }

        // Still waiting, past the date somebody promised it back.
        if ($pause && $pause->expected_resume_on && $pause->expected_resume_on->isPast()) {
            $days = (int) $pause->expected_resume_on->startOfDay()->diffInDays(now()->startOfDay());
            $who = str_replace('_', ' ', $pause->responsible_party ?? 'somebody');
            $out[] = [
                TaskEscalation::TRIGGER_PAUSE_OVERRUN, $days,
                "Waiting on {$who} since {$pause->paused_at->toDateString()}; was due back {$pause->expected_resume_on->toDateString()}, {$days} day(s) ago.",
            ];
        }

        $slips = $task->relationLoaded('extensions') ? $task->extensions->count() : $task->extensions()->count();
        if ($slips >= min(array_keys(self::LADDER[TaskEscalation::TRIGGER_REPEAT_SLIPS]))) {
            $out[] = [TaskEscalation::TRIGGER_REPEAT_SLIPS, $slips, "The deadline has moved {$slips} times."];
        }

        return $out;
    }

    private function climb(Task $task, string $trigger, string $level, string $detail, bool $quietly = false): void
    {
        $to = $this->targetFor($task, $level);

        $escalation = TaskEscalation::create([
            'organization_id' => $task->organization_id,
            'task_id'         => $task->id,
            'trigger'         => $trigger,
            'level'           => $level,
            'to_employee_id'  => $to,
            'detail'          => $detail,
            'escalated_at'    => now(),
        ]);

        // The row is written whether or not anybody holds the role — a rung
        // reached with nobody on it is worth knowing, and without the row the
        // ladder would try again every night forever.
        if ($to && ! $quietly) {
            $this->notifier->escalatedTask($task, $escalation);
        }

        $task->unsetRelation('escalations');
    }

    /** Who sits at this rung, where anybody does. */
    public function targetFor(Task $task, string $level): ?int
    {
        return match ($level) {
            TaskEscalation::LEVEL_OWNER => $task->assigned_to,

            TaskEscalation::LEVEL_PM => $this->projectPerson($task, [ProjectPerson::KIND_PM, ProjectPerson::KIND_MANAGER, ProjectPerson::KIND_LEAD]),

            // The department the task was directed at, else the one its owner
            // sits in — a task nobody routed still belongs to somebody's team.
            TaskEscalation::LEVEL_DEPARTMENT => $this->departmentLead($task),

            TaskEscalation::LEVEL_SPONSOR => $this->projectPerson($task, [ProjectPerson::KIND_SPONSOR, ProjectPerson::KIND_OWNER]),

            default => null,
        };
    }

    /** @param list<string> $kinds */
    private function projectPerson(Task $task, array $kinds): ?int
    {
        if (! $task->project_id) {
            return null;
        }

        return ProjectPerson::where('project_id', $task->project_id)
            ->whereIn('role_kind', $kinds)
            ->whereNotNull('employee_id')
            // In the order asked for, so a project manager beats a lead.
            ->orderByRaw('array_position(?::varchar[], role_kind)', ['{' . implode(',', $kinds) . '}'])
            ->value('employee_id');
    }

    private function departmentLead(Task $task): ?int
    {
        $departmentId = $task->department_id
            ?? ($task->assigned_to
                ? \App\Models\Employee::whereKey($task->assigned_to)->value('department_id')
                : null);

        if (! $departmentId) {
            return null;
        }

        $lead = \App\Models\Department::whereKey($departmentId)->value('lead_employee_id');

        // Escalating a task to the person it is already assigned to is not an
        // escalation; it is the same notification twice.
        return $lead === $task->assigned_to ? null : $lead;
    }

    /** @return Collection<int, TaskPause> */
    public function overrunningPauses(int $organizationId): Collection
    {
        return TaskPause::where('organization_id', $organizationId)
            ->whereNull('resumed_at')
            ->whereNotNull('expected_resume_on')
            ->whereDate('expected_resume_on', '<', now()->toDateString())
            ->with('task')
            ->get();
    }
}
