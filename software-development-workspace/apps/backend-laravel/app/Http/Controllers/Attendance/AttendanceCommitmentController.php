<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Attendance\AttendanceCommitment;
use App\Models\Attendance\AttendanceDay;
use App\Models\Task\Task;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\CommitmentReporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What somebody committed to for a day, and how it went.
 *
 * The module is not a timesheet. It asks three things and only three: what did
 * you say you would finish today, did you finish it, and if not, why not. The
 * third is the one that has to be enforced, because it is the only one people
 * skip and the only one anybody else needs.
 */
class AttendanceCommitmentController extends Controller
{
    public function __construct(
        private AttendanceService $attendance,
        private CommitmentReporter $reporter,
    ) {
    }

    /**
     * Commit to something for a day.
     *
     * Takes a date rather than a day id, because committing to Tuesday should
     * not require the caller to create Tuesday first.
     */
    public function store(Request $request): JsonResponse
    {
        $orgId = app('current.organization_id');

        $data = $request->validate([
            'work_date' => ['required', 'date'],
            'employee_id' => ['nullable', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $orgId)],

            // Existing work where there is any. Optional, because "call the
            // client back" is a real commitment nobody wants as a task record.
            'task_id' => ['nullable', 'integer',
                Rule::exists('tasks', 'id')->where('organization_id', $orgId)],

            'title' => ['required_without:task_id', 'nullable', 'string', 'max:255'],
            'kind'  => ['sometimes', Rule::in(AttendanceCommitment::KINDS)],
            'note'  => ['nullable', 'string', 'max:2000'],

            /*
             * Optional, and nothing is built on them. Somebody who wants to note
             * that the client call is at eleven still can — but a grid you must
             * fill in is a timesheet, and timesheets get completed on Friday
             * from memory.
             */
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at'   => ['nullable', 'date_format:H:i', 'after:starts_at'],
        ]);

        $employeeId = $data['employee_id'] ?? $request->user()->employeeId();
        abort_if($employeeId === null, 422, 'You have no employee record.');

        $day = $this->attendance->dayFor(
            $orgId, $employeeId,
            CarbonImmutable::parse($data['work_date'])->toDateString(),
            $request->user()->id,
        );

        $this->authorize('record', $day);

        // Copied rather than read through the task, so the day still reads
        // after a rename and one task split across days can say which part.
        $title = $data['title'] ?? null;

        if ($title === null && ! empty($data['task_id'])) {
            $title = Task::whereKey($data['task_id'])->value('title');
        }

        $day->commitments()->create([
            'organization_id' => $orgId,
            'task_id' => $data['task_id'] ?? null,
            'title'   => $title,
            'kind'    => $data['kind'] ?? (! empty($data['task_id']) ? 'work' : 'admin'),
            'position' => (int) $day->commitments()->max('position') + 1,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at'   => $data['ends_at'] ?? null,
            'note'      => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->dayPayload($day)]);
    }

    /**
     * Say how it went.
     *
     * A miss without a reason is refused. That refusal is the feature: "why not"
     * asked on the day by the person who knows is worth more than any report,
     * and asked a fortnight later it gets "can't remember".
     */
    public function report(Request $request, AttendanceCommitment $commitment): JsonResponse
    {
        $day = $commitment->day;
        $this->authorize('record', $day);

        $data = $request->validate([
            'outcome' => ['required', Rule::in(AttendanceCommitment::OUTCOMES)],
            'reason'  => ['nullable', 'string', 'max:2000'],
            // Moved to another day rather than abandoned — the common answer
            // to "why not", and worth recording as a link rather than prose.
            'carry_to' => ['nullable', 'date'],
        ]);

        if ($data['outcome'] === 'not_done' && ! filled($data['reason'] ?? null)) {
            throw ValidationException::withMessages([
                'reason' => 'Say what happened. Somebody else is waiting on this.',
            ]);
        }

        $commitment->update([
            'outcome' => $data['outcome'],
            'reason'  => $data['outcome'] === 'done' ? null : $data['reason'],
            'reported_at' => now(),
            'reported_by' => $request->user()->employeeId(),
        ]);

        if ($data['outcome'] === 'not_done' && ! empty($data['carry_to'])) {
            $this->reporter->carry($commitment, $data['carry_to'], $request->user()->id);
        }

        /*
         * Told, not filed. "Effective communication if not completed" means
         * somebody hears about it — a note in a record nobody opens is the
         * same as no note at all.
         */
        if ($data['outcome'] === 'not_done') {
            $this->reporter->announce($commitment->fresh(['day.employee']));
        }

        return response()->json(['data' => $this->dayPayload($day)]);
    }

    public function update(Request $request, AttendanceCommitment $commitment): JsonResponse
    {
        $this->authorize('record', $commitment->day);

        $commitment->update($request->validate([
            'title'    => ['sometimes', 'string', 'max:255'],
            'kind'     => ['sometimes', Rule::in(AttendanceCommitment::KINDS)],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'note'     => ['nullable', 'string', 'max:2000'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at'   => ['nullable', 'date_format:H:i'],
        ]));

        return response()->json(['data' => $this->dayPayload($commitment->day)]);
    }

    /**
     * Drop a commitment.
     *
     * Only while it is unanswered. Deleting one that was reported as missed
     * would erase the explanation along with it, which is exactly the record
     * this exists to keep.
     */
    public function destroy(AttendanceCommitment $commitment): JsonResponse
    {
        $day = $commitment->day;
        $this->authorize('record', $day);

        abort_if(
            $commitment->outcome !== null,
            422,
            'This has already been reported on. Change the outcome rather than removing the record.',
        );

        $commitment->delete();

        return response()->json(['data' => $this->dayPayload($day)]);
    }

    private function dayPayload(AttendanceDay $day): array
    {
        return app(AttendanceController::class)->dayWithPlan($day->fresh([
            'employee', 'account', 'punches', 'commitments.task', 'commitments.carriedTo',
        ]));
    }
}
