<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Attendance\AttendanceBlock;
use App\Models\Attendance\AttendanceDay;
use App\Models\Task\Task;
use App\Services\Attendance\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Planning a day.
 *
 * A block gives existing work a slot. It does not create work — the Add Task
 * modal offers Project and Task as dropdowns, which settles the question: this
 * is the Tasks module seen through a clock, not a second place to write tasks
 * down.
 */
class AttendanceBlockController extends Controller
{
    public function __construct(private AttendanceService $attendance)
    {
    }

    /**
     * Put something in the day.
     *
     * Takes a date rather than a day id, because planning next Tuesday should
     * not require the caller to create Tuesday first. The day is made if it is
     * not there.
     */
    public function store(Request $request): JsonResponse
    {
        $orgId = app('current.organization_id');

        $data = $request->validate([
            'work_date' => ['required', 'date'],
            'employee_id' => ['nullable', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $orgId)],

            // Existing work, or nothing — Break and Lunch have no task behind
            // them and forcing one would fill the task list with rubbish.
            'task_id' => ['nullable', 'integer',
                Rule::exists('tasks', 'id')->where('organization_id', $orgId)],

            'title'  => ['required_without:task_id', 'nullable', 'string', 'max:255'],
            'kind'   => ['sometimes', Rule::in(AttendanceBlock::KINDS)],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at'   => ['required', 'date_format:H:i', 'after:starts_at'],
            'note'      => ['nullable', 'string', 'max:2000'],
        ]);

        $employeeId = $data['employee_id'] ?? $request->user()->employeeId();
        abort_if($employeeId === null, 422, 'You have no employee record.');

        $day = $this->attendance->dayFor(
            $orgId, $employeeId,
            CarbonImmutable::parse($data['work_date'])->toDateString(),
            $request->user()->id,
        );

        $this->authorize('record', $day);

        /*
         * The title is copied from the task rather than read through it, so
         * the timeline still reads after the task is renamed — and so one task
         * split across a morning and an afternoon can say which half is which.
         */
        $title = $data['title'] ?? null;

        if ($title === null && ! empty($data['task_id'])) {
            $title = Task::whereKey($data['task_id'])->value('title');
        }

        $day->blocks()->create([
            'organization_id' => $orgId,
            'task_id'   => $data['task_id'] ?? null,
            'title'     => $title,
            'kind'      => $data['kind'] ?? (! empty($data['task_id']) ? 'work' : 'admin'),
            'starts_at' => $data['starts_at'],
            'ends_at'   => $data['ends_at'],
            'note'      => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->dayPayload($day)]);
    }

    /** Move or resize one. */
    public function update(Request $request, AttendanceBlock $block): JsonResponse
    {
        $day = $block->day;
        $this->authorize('record', $day);

        $data = $request->validate([
            'title'     => ['sometimes', 'string', 'max:255'],
            'kind'      => ['sometimes', Rule::in(AttendanceBlock::KINDS)],
            'starts_at' => ['sometimes', 'date_format:H:i'],
            'ends_at'   => ['sometimes', 'date_format:H:i'],
            'note'      => ['nullable', 'string', 'max:2000'],
        ]);

        /*
         * Checked before the write, against the span as it would end up.
         *
         * A request that moves only one end has to be judged on both, and
         * writing first then rolling back reads the already-synced values —
         * which is a rollback that restores nothing.
         */
        $starts = $data['starts_at'] ?? substr((string) $block->starts_at, 0, 5);
        $ends   = $data['ends_at']   ?? substr((string) $block->ends_at, 0, 5);

        abort_if($ends <= $starts, 422, 'A block has to end after it starts.');

        $block->update($data);

        return response()->json(['data' => $this->dayPayload($day)]);
    }

    public function destroy(AttendanceBlock $block): JsonResponse
    {
        $day = $block->day;
        $this->authorize('record', $day);

        $block->delete();

        return response()->json(['data' => $this->dayPayload($day)]);
    }

    /**
     * The day and its plan.
     *
     * Returned from every write so the timeline redraws from one answer rather
     * than the client patching its own copy and drifting.
     */
    private function dayPayload(AttendanceDay $day): array
    {
        return app(AttendanceController::class)->dayWithPlan($day->fresh([
            'employee', 'account', 'punches', 'blocks.task',
        ]));
    }
}
