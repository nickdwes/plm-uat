<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendancePolicy;
use App\Models\Employee;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\EnablementEligibility;
use App\Services\NotificationService;
use App\Support\Authorization\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The one day a week for training, certification, research and improvement.
 *
 * Requested rather than taken, and approved by the person accountable for the
 * work it displaces. The conflicts are surfaced to both sides: a rule that
 * silently refuses teaches people to stop asking, and one that silently allows
 * leaves a manager approving a day they would have refused had they known the
 * client is on site.
 */
class EnablementDayController extends Controller
{
    public function __construct(
        private AttendanceService $attendance,
        private EnablementEligibility $eligibility,
        private NotificationService $notifications,
    ) {
    }

    /**
     * What is in the way, before anybody commits to asking.
     *
     * Separate from the request so somebody can check Thursday, see the client
     * is on site, and quietly ask for Friday instead — rather than putting a
     * request in front of their manager only to withdraw it.
     */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate(['work_date' => ['required', 'date']]);

        $orgId = app('current.organization_id');
        $employeeId = $request->user()->employeeId();
        abort_if($employeeId === null, 422, 'You have no employee record.');

        $date = CarbonImmutable::parse($data['work_date']);

        return response()->json([
            'work_date'  => $date->toDateString(),
            'conflicts'  => $this->eligibility->conflictsFor($orgId, $employeeId, $date->toDateString()),
            'already_taken' => $this->weekAlreadySpoken($orgId, $employeeId, $date)?->work_date?->toDateString(),
        ]);
    }

    /**
     * Ask for a day.
     *
     * One a week, counted across requested and approved alike — a pending
     * request is a claim on the week, and letting somebody ask for three while
     * waiting would make the rule meaningless.
     */
    public function request(Request $request): JsonResponse
    {
        $orgId  = app('current.organization_id');
        $policy = AttendancePolicy::forOrganization($orgId);

        $data = $request->validate([
            'work_date' => ['required', 'date'],
            'note'      => ['nullable', 'string', 'max:2000'],
        ]);

        $employeeId = $request->user()->employeeId();
        abort_if($employeeId === null, 422, 'You have no employee record.');

        $date = CarbonImmutable::parse($data['work_date']);

        // The point of the day is to plan for it. Asking for yesterday is
        // paperwork after the fact, which is not what this is for.
        if ($date->lessThan(now()->startOfDay())) {
            throw ValidationException::withMessages([
                'work_date' => 'Enablement days are asked for in advance.',
            ]);
        }

        if ($date->isAfter(now()->addDays($policy->plan_ahead_days))) {
            throw ValidationException::withMessages([
                'work_date' => "You can only plan {$policy->plan_ahead_days} days ahead.",
            ]);
        }

        if (! $policy->worksOn($date)) {
            throw ValidationException::withMessages([
                'work_date' => 'That is not a working day.',
            ]);
        }

        if ($taken = $this->weekAlreadySpoken($orgId, $employeeId, $date)) {
            throw ValidationException::withMessages([
                'work_date' => 'You already have an enablement day that week, on '
                    . $taken->work_date->format('j M') . '.',
            ]);
        }

        $day = $this->attendance->dayFor($orgId, $employeeId, $date->toDateString(), $request->user()->id);

        $day->update([
            'is_enablement_day' => true,
            'enablement_status' => 'requested',
            'enablement_note'   => $data['note'] ?? null,
            // The day is worked from home by definition, so the plan says so
            // rather than making somebody state it twice.
            'planned_location'  => $day->planned_location ?? 'home',
            'enablement_approved_by' => null,
            'enablement_actioned_at' => null,
        ]);

        $this->tellManager($day, $employeeId);

        return response()->json(['data' => $this->row($day->fresh(['employee', 'approver']))]);
    }

    /**
     * Everything waiting on the person reading this.
     *
     * A request nobody is shown is a request nobody answers — the same failure
     * the task module's 261 unanswered delegations were.
     */
    public function pending(Request $request): JsonResponse
    {
        $me = $request->user()->employeeId();

        $q = AttendanceDay::with(['employee', 'approver'])
            ->where('enablement_status', 'requested')
            ->orderBy('work_date');

        if (! $request->user()->hasPermission(Permission::ATTENDANCE_MANAGE)) {
            $q->whereIn('employee_id', Employee::where('reports_to', $me)->select('id'));
        }

        return response()->json([
            'data' => $q->get()->map(fn (AttendanceDay $d) => $this->row($d, withConflicts: true)),
        ]);
    }

    /**
     * Approve or decline.
     *
     * The line manager, or somebody who manages attendance. Not the person
     * asking: an enablement day displaces work other people are waiting on, and
     * self-approval would make the request a formality.
     */
    public function decide(Request $request, AttendanceDay $attendanceDay): JsonResponse
    {
        $me = $request->user()->employeeId();

        abort_unless(
            $attendanceDay->employee?->reports_to === $me
                || $request->user()->hasPermission(Permission::ATTENDANCE_MANAGE),
            403,
            'This is not yours to decide.',
        );

        abort_if($attendanceDay->employee_id === $me, 403, 'You cannot approve your own enablement day.');
        abort_unless($attendanceDay->enablement_status === 'requested', 422, 'That has already been decided.');

        $data = $request->validate([
            'approved' => ['required', 'boolean'],
            'note'     => ['nullable', 'string', 'max:2000'],
        ]);

        $attendanceDay->update([
            'enablement_status' => $data['approved'] ? 'approved' : 'declined',
            'enablement_approved_by' => $me,
            'enablement_actioned_at' => now(),
            'enablement_note' => $data['note'] ?? $attendanceDay->enablement_note,
            /*
             * A declined day is an ordinary day again. Leaving the flag set
             * would count it in the enablement figures and leave the person's
             * plan saying "home" for a day nobody agreed to.
             */
            'is_enablement_day' => $data['approved'],
            'planned_location'  => $data['approved'] ? 'home' : null,
        ]);

        $this->tellRequester($attendanceDay, $data['approved'], $data['note'] ?? null);

        return response()->json(['data' => $this->row($attendanceDay->fresh(['employee', 'approver']))]);
    }

    /** Withdraw one that has not been decided. */
    public function withdraw(Request $request, AttendanceDay $attendanceDay): JsonResponse
    {
        abort_unless($attendanceDay->employee_id === $request->user()->employeeId(), 403, 'Not yours.');
        abort_unless($attendanceDay->enablement_status === 'requested', 422, 'That has already been decided.');

        $attendanceDay->update([
            'is_enablement_day' => false,
            'enablement_status' => null,
            'enablement_note'   => null,
            'planned_location'  => null,
        ]);

        return response()->json(['data' => $this->row($attendanceDay->fresh(['employee', 'approver']))]);
    }

    // ── Plumbing ──────────────────────────────────────────────────────────

    /**
     * An enablement day already claimed in the same week.
     *
     * Monday to Sunday, and counting requested alongside approved: a pending
     * request is a claim on the week, and letting somebody ask for three while
     * waiting would make the rule meaningless.
     */
    private function weekAlreadySpoken(int $orgId, int $employeeId, CarbonImmutable $date): ?AttendanceDay
    {
        return AttendanceDay::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('employee_id', $employeeId)
            ->where('is_enablement_day', true)
            ->whereIn('enablement_status', ['requested', 'approved'])
            ->whereBetween('work_date', [
                $date->startOfWeek()->toDateString(),
                $date->endOfWeek()->toDateString(),
            ])
            ->whereDate('work_date', '!=', $date->toDateString())
            ->first();
    }

    private function tellManager(AttendanceDay $day, int $employeeId): void
    {
        $employee = Employee::find($employeeId);
        $userId = $employee?->reports_to
            ? Employee::whereKey($employee->reports_to)->value('user_id')
            : null;

        if ($userId === null) {
            return;
        }

        $this->notifications->create(
            (string) $day->organization_id,
            $userId,
            'attendance.enablement_requested',
            ($employee?->full_name ?? 'Somebody') . ' asked for an enablement day',
            $day->work_date->format('l j F') . ($day->enablement_note ? ' — ' . $day->enablement_note : ''),
            ['attendance_day_id' => $day->id],
            AttendanceDay::class,
            (string) $day->id,
        );
    }

    private function tellRequester(AttendanceDay $day, bool $approved, ?string $note): void
    {
        $userId = Employee::whereKey($day->employee_id)->value('user_id');

        if ($userId === null) {
            return;
        }

        $this->notifications->create(
            (string) $day->organization_id,
            $userId,
            $approved ? 'attendance.enablement_approved' : 'attendance.enablement_declined',
            $approved ? 'Your enablement day is approved' : 'Your enablement day was declined',
            $day->work_date->format('l j F') . ($note ? ' — ' . $note : ''),
            ['attendance_day_id' => $day->id],
            AttendanceDay::class,
            (string) $day->id,
        );
    }

    private function row(AttendanceDay $d, bool $withConflicts = false): array
    {
        $payload = [
            'id'        => $d->id,
            'work_date' => $d->work_date->toDateString(),
            'status'    => $d->enablement_status,
            'note'      => $d->enablement_note,
            'actioned_at' => $d->enablement_actioned_at?->toIso8601String(),
            'employee'  => $d->relationLoaded('employee') && $d->employee
                ? ['id' => $d->employee->id, 'name' => $d->employee->full_name] : null,
            'approver'  => $d->relationLoaded('approver') && $d->approver
                ? ['id' => $d->approver->id, 'name' => $d->approver->full_name] : null,
        ];

        if ($withConflicts) {
            // Shown to the approver, so a decision is made knowing what the day
            // displaces rather than on the strength of the date alone.
            $payload['conflicts'] = $this->eligibility->conflictsFor(
                $d->organization_id, $d->employee_id, $d->work_date->toDateString(),
            );
        }

        return $payload;
    }
}
