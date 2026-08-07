<?php

namespace App\Http\Controllers\Task;

use App\Http\Controllers\Controller;
use App\Models\Task\Task;
use App\Models\Task\TaskRecurrence;
use App\Services\Task\TaskGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Schedules for work that comes back round.
 *
 * The legacy had no recurrence at all, which is why its 9,055 tasks include the
 * same monthly reviews and renewals typed out by hand over and over.
 *
 * Gated on the task permissions rather than its own set: a schedule is a way of
 * creating tasks, and somebody who may create one may set up the thing that
 * creates them.
 */
class TaskRecurrenceController extends Controller
{
    public function __construct(private TaskGenerator $generator)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Task::class);

        $rows = TaskRecurrence::with('assignee')->latest('id')->get();

        return response()->json(['data' => $rows->map(fn ($r) => $this->row($r))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Task::class);

        $orgId = app('current.organization_id');

        $recurrence = TaskRecurrence::create($this->rules($request, $orgId) + [
            'organization_id' => $orgId,
            'created_by'      => $request->user()->id,
        ]);

        return response()->json(['data' => $this->row($recurrence->load('assignee'))], 201);
    }

    public function update(Request $request, TaskRecurrence $recurrence): JsonResponse
    {
        $this->authorize('create', Task::class);

        $recurrence->update($this->rules($request, $recurrence->organization_id));

        return response()->json(['data' => $this->row($recurrence->fresh('assignee'))]);
    }

    /**
     * Stopped rather than deleted.
     *
     * The tasks it has already raised stay, and stay explicable. Deleting the
     * schedule would leave a year of tasks whose origin nobody can account for.
     */
    public function destroy(TaskRecurrence $recurrence): JsonResponse
    {
        $this->authorize('create', Task::class);

        $recurrence->update(['is_active' => false]);

        return response()->json(['data' => $this->row($recurrence->fresh('assignee'))]);
    }

    /**
     * Raise what this schedule owes now, without waiting for the nightly run.
     *
     * Safe to press twice — every task is keyed to its schedule and occurrence
     * date, so the second press makes nothing.
     */
    public function run(TaskRecurrence $recurrence): JsonResponse
    {
        $this->authorize('create', Task::class);

        $made = $this->generator->runRecurrence($recurrence);

        return response()->json([
            'created' => count($made),
            'data'    => $this->row($recurrence->fresh('assignee')),
        ]);
    }

    // ── Shapes ────────────────────────────────────────────────────────────

    private function rules(Request $request, int $orgId): array
    {
        $scoped = fn (string $table) => Rule::exists($table, 'id')->where('organization_id', $orgId);

        $data = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:50000'],
            'category'    => ['sometimes', Rule::in(Task::CATEGORIES)],
            'priority'    => ['sometimes', Rule::in(Task::PRIORITIES)],
            'assigned_to' => ['nullable', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $orgId)],
            'effort_estimate_days' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],

            'project_id' => ['nullable', 'integer', $scoped('projects')],
            'account_id' => ['nullable', 'integer', $scoped('accounts')],

            'frequency' => ['required', Rule::in(TaskRecurrence::FREQUENCIES)],
            'interval'  => ['sometimes', 'integer', 'min:1', 'max:52'],
            // Days after each occurrence, because a repeating task's deadline
            // is relative to the occurrence rather than a date in the calendar.
            'lead_days' => ['sometimes', 'integer', 'min:0', 'max:365'],

            'starts_on' => ['required', 'date'],
            'ends_on'   => ['nullable', 'date', 'after_or_equal:starts_on'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        /*
         * A schedule that starts years back would raise a wall of tasks on its
         * first run. That is a typo rather than an intention, and refusing it
         * is kinder than generating twenty-four tasks and letting somebody
         * delete them one at a time.
         */
        if ($request->isMethod('POST')
            && now()->diffInMonths(\Carbon\CarbonImmutable::parse($data['starts_on']), false) < -12) {
            throw ValidationException::withMessages([
                'starts_on' => 'Starting more than a year ago would raise a backlog of tasks. Pick a recent date.',
            ]);
        }

        return $data;
    }

    private function row(TaskRecurrence $r): array
    {
        return [
            'id'          => $r->id,
            'title'       => $r->title,
            'description' => $r->description,
            'category'    => $r->category,
            'priority'    => $r->priority,
            'frequency'   => $r->frequency,
            'interval'    => $r->interval,
            'lead_days'   => $r->lead_days,
            'starts_on'   => $r->starts_on?->toDateString(),
            'ends_on'     => $r->ends_on?->toDateString(),
            'is_active'   => $r->is_active,
            'effort_estimate_days' => $r->effort_estimate_days,
            'last_generated_for'   => $r->last_generated_for?->toDateString(),

            // What it will raise next, so a schedule can be checked without
            // waiting a month to find out it was set up wrong.
            'next_due' => ($due = $r->due(now()->toImmutable()->addYear(), 1)) ? $due[0]->toDateString() : null,

            'assignee' => $r->assignee ? ['id' => $r->assignee->id, 'name' => $r->assignee->full_name] : null,
        ];
    }
}
