<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendancePolicy;
use App\Models\Attendance\AttendancePunch;
use App\Models\Employee;
use App\Policies\Attendance\AttendanceDayPolicy;
use App\Services\Attendance\AttendanceService;
use App\Support\Authorization\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Attendance and where people are working.
 *
 * One list endpoint behind the calendar and the list view, because they are two
 * pictures of the same days and two endpoints would eventually disagree about
 * one of them.
 */
class AttendanceController extends Controller
{
    public function __construct(private AttendanceService $attendance)
    {
    }

    /**
     * The days in a window.
     *
     * Both designed views read this: the calendar takes a month, the list takes
     * whatever range is asked for. Same rows, same statuses, arranged twice.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceDay::class);

        [$from, $to] = $this->window($request);

        // Blocks come with the list because the week and day views draw them;
        // without eager loading that is a query per day on screen.
        $q = AttendanceDay::with(['employee', 'account', 'punches', 'commitments.task'])
            ->between($from, $to)
            ->orderBy('work_date');

        $this->scopeToReach($request, $q);

        if ($employeeId = $request->integer('employee_id')) {
            $this->assertReaches($request, $employeeId);
            $q->where('employee_id', $employeeId);
        }

        if ($location = $request->string('location')->toString()) {
            $q->where('actual_location', $location);
        }

        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }

        return response()->json([
            'data' => $q->get()->map(fn (AttendanceDay $d) => $this->dayWithPlan($d)),
            'range' => ['from' => $from, 'to' => $to],
        ]);
    }

    /**
     * The three cards across the top of the designs.
     *
     * Counted from the same rows the list shows, so the number on the card and
     * the length of the list can never tell different stories.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceDay::class);

        [$from, $to] = $this->window($request);

        $q = AttendanceDay::query()->between($from, $to);
        $this->scopeToReach($request, $q);

        if ($employeeId = $request->integer('employee_id')) {
            $this->assertReaches($request, $employeeId);
            $q->where('employee_id', $employeeId);
        }

        $counts = (clone $q)->selectRaw('status, count(*) as n')->groupBy('status')
            ->pluck('n', 'status');

        $at = fn (string $key) => (int) ($counts[$key] ?? 0);

        return response()->json([
            'present' => [
                'on_time' => $at('on_time'),
                'late'    => $at('late'),
                'early'   => $at('early'),
            ],
            'not_present' => [
                'absent'       => $at('absent'),
                'no_clock_in'  => $at('no_clock_in'),
                // Somebody who came and forgot to close the day. A different
                // failure from not turning up, and counting them together
                // would accuse people of absence for a missed button press.
                'no_clock_out' => $at('no_clock_out'),
            ],
            'away' => [
                'on_leave' => $at('on_leave'),
                'holiday'  => $at('holiday'),
            ],

            /*
             * Where the work happened. Not in the designs' three cards, but it
             * is the whole point of asking people to report a location, and a
             * module that collects it without ever showing it teaches people
             * the question does not matter.
             */
            'locations' => [
                'office'        => (clone $q)->where('actual_location', 'office')->count(),
                'home'          => (clone $q)->where('actual_location', 'home')->count(),
                'customer_site' => (clone $q)->where('actual_location', 'customer_site')->count(),
            ],

            /*
             * How the work went, which is what the module is actually for.
             *
             * `unexplained` is the number worth watching: not how much slipped,
             * because work slips and reacting to that is reacting to noise, but
             * how much slipped with nobody told. Days still running are left
             * out — an unanswered commitment at three in the afternoon is not a
             * failure to communicate.
             */
            'work' => $this->workTally($from, $to, clone $q),
        ]);
    }

    /**
     * Promised, delivered, and missed in silence, across the window.
     *
     * Counted in the database rather than by loading every commitment, because
     * a month across a team is thousands of rows and this sits on a card people
     * open constantly.
     */
    private function workTally(string $from, string $to, $days): array
    {
        $dayIds = $days->select('attendance_days.id')->pluck('id');

        if ($dayIds->isEmpty()) {
            return ['promised' => 0, 'done' => 0, 'missed' => 0, 'unexplained' => 0];
        }

        $base = \App\Models\Attendance\AttendanceCommitment::whereIn('attendance_day_id', $dayIds);

        // Days still running are excluded from the silence count: an unanswered
        // commitment at three in the afternoon is not a failure to communicate.
        $settled = (clone $base)->whereHas(
            'day',
            fn ($d) => $d->whereDate('work_date', '<', now()->toDateString()),
        );

        return [
            'promised' => (clone $base)->count(),
            'done'     => (clone $base)->where('outcome', 'done')->count(),
            'missed'   => (clone $base)->where('outcome', 'not_done')->count(),
            'unexplained' => (clone $settled)->where(
                fn ($w) => $w->whereNull('outcome')
                    ->orWhere(fn ($x) => $x->where('outcome', 'not_done')->whereNull('reason')),
            )->count(),
        ];
    }

    /** One day, with its punches and its history of changes. */
    public function show(Request $request, AttendanceDay $attendanceDay): JsonResponse
    {
        $this->authorize('view', $attendanceDay);

        return response()->json([
            'data' => $this->dayWithPlan(
                $attendanceDay->load([
                    'employee', 'account', 'punches', 'commitments.task', 'commitments.carriedTo',
                    'audits.changedBy', 'approver',
                ]),
                withAudit: true,
            ),
        ]);
    }

    /**
     * Say where you are working, on the day or in advance.
     *
     * Upserts, because "report my location for Thursday" is the same intent
     * whether or not Thursday already has a row — and making the caller find
     * out first would just move the problem.
     */
    public function record(Request $request): JsonResponse
    {
        $orgId  = app('current.organization_id');
        $policy = AttendancePolicy::forOrganization($orgId);

        $data = $request->validate([
            'work_date'   => ['required', 'date'],
            'employee_id' => ['nullable', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $orgId)],
            'planned_location' => ['nullable', Rule::in(AttendanceDay::LOCATIONS)],
            'actual_location'  => ['nullable', Rule::in(AttendanceDay::LOCATIONS)],
            'account_id'    => ['nullable', 'integer',
                Rule::exists('accounts', 'id')->where('organization_id', $orgId)],
            'location_note' => ['nullable', 'string', 'max:255'],
            'notes'         => ['nullable', 'string', 'max:2000'],
        ]);

        $employeeId = $data['employee_id'] ?? $request->user()->employeeId();
        abort_if($employeeId === null, 422, 'You have no employee record.');

        $date = CarbonImmutable::parse($data['work_date']);

        /*
         * How far ahead somebody may plan is policy, not code. The requirement
         * allows advance reporting "subject to company policy", so the limit is
         * a setting somebody can change without a deploy.
         */
        if ($date->isAfter(now()->addDays($policy->plan_ahead_days))) {
            throw ValidationException::withMessages([
                'work_date' => "You can only plan {$policy->plan_ahead_days} days ahead.",
            ]);
        }

        // A customer site with no customer named is the one combination that
        // makes the engagement figures unanswerable.
        if (($data['actual_location'] ?? $data['planned_location'] ?? null) === 'customer_site'
            && ! ($data['account_id'] ?? null)) {
            throw ValidationException::withMessages([
                'account_id' => 'Say which customer, or the site visit cannot be counted.',
            ]);
        }

        $day = $this->attendance->dayFor($orgId, $employeeId, $date->toDateString(), $request->user()->id);

        $this->authorize('record', $day);

        // Captured before the write; by the time anything interesting has
        // happened the model already holds the new value.
        $before = $day->only(['planned_location', 'actual_location', 'account_id', 'notes']);

        $day->fill(array_diff_key($data, array_flip(['work_date', 'employee_id'])));
        $day->save();

        $this->attendance->audit($day, $before, $request->user()->id);
        $this->attendance->recalculate($day);

        return response()->json(['data' => $this->row($day->fresh(['employee', 'account', 'punches']))]);
    }

    // ── The clock ─────────────────────────────────────────────────────────

    public function clockIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_type' => ['nullable', Rule::in(AttendanceDay::LOCATIONS)],
        ]);

        $day = $this->today($request);
        $this->authorize('record', $day);

        $this->attendance->clockIn($day, $data['location_type'] ?? null, $request->user()->id);

        return response()->json(['data' => $this->row($day->fresh(['employee', 'account', 'punches']))]);
    }

    public function clockOut(Request $request): JsonResponse
    {
        $day = $this->today($request);
        $this->authorize('record', $day);

        $punch = $this->attendance->clockOut($day);

        abort_if($punch === null, 422, 'You are not clocked in.');

        return response()->json(['data' => $this->row($day->fresh(['employee', 'account', 'punches']))]);
    }

    /**
     * Hours entered after the fact — the designs' "Add Hours" tab.
     *
     * Kept apart from clocking in, and marked, because a figure somebody typed
     * and one the clock recorded are different evidence.
     */
    public function addHours(Request $request): JsonResponse
    {
        $orgId = app('current.organization_id');

        $data = $request->validate([
            'work_date' => ['required', 'date', 'before_or_equal:today'],
            'from'      => ['required', 'date_format:H:i'],
            'to'        => ['required', 'date_format:H:i', 'after:from'],
            'note'      => ['nullable', 'string', 'max:2000'],
            'employee_id' => ['nullable', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $orgId)],
        ]);

        $employeeId = $data['employee_id'] ?? $request->user()->employeeId();
        abort_if($employeeId === null, 422, 'You have no employee record.');

        $day = $this->attendance->dayFor($orgId, $employeeId, $data['work_date'], $request->user()->id);
        $this->authorize('record', $day);

        $date = CarbonImmutable::parse($data['work_date']);

        $this->attendance->addHours(
            $day,
            $date->setTimeFromTimeString($data['from'])->toDateTimeString(),
            $date->setTimeFromTimeString($data['to'])->toDateTimeString(),
            $data['note'] ?? null,
            $request->user()->id,
        );

        return response()->json(['data' => $this->row($day->fresh(['employee', 'account', 'punches']))]);
    }

    // ── The policy ────────────────────────────────────────────────────────

    public function policy(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceDay::class);

        return response()->json(['data' => $this->policyPayload(
            AttendancePolicy::forOrganization(app('current.organization_id')),
        )]);
    }

    public function updatePolicy(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission(Permission::ATTENDANCE_MANAGE),
            403,
            'Only somebody who manages attendance can set the working day.',
        );

        $data = $request->validate([
            'work_starts_at' => ['sometimes', 'date_format:H:i'],
            'work_ends_at'   => ['sometimes', 'date_format:H:i'],
            'grace_minutes'  => ['sometimes', 'integer', 'min:0', 'max:120'],
            'standard_hours' => ['sometimes', 'numeric', 'min:1', 'max:24'],
            'overtime_after_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'working_days'   => ['sometimes', 'array', 'min:1'],
            'working_days.*' => ['integer', 'min:1', 'max:7'],
            'plan_ahead_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
        ]);

        $policy = AttendancePolicy::forOrganization(app('current.organization_id'));
        $policy->update($data);

        /*
         * Deliberately not recalculating past days. The status was decided by
         * the policy as it stood at the time, and moving the office start time
         * must not retroactively make last year's punctual staff late.
         */
        return response()->json(['data' => $this->policyPayload($policy)]);
    }

    // ── Plumbing ──────────────────────────────────────────────────────────

    private function today(Request $request): AttendanceDay
    {
        $employeeId = $request->user()->employeeId();
        abort_if($employeeId === null, 422, 'You have no employee record.');

        return $this->attendance->dayFor(
            app('current.organization_id'),
            $employeeId,
            now()->toDateString(),
            $request->user()->id,
        );
    }

    /** The month by default, because that is what the calendar opens on. */
    private function window(Request $request): array
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $to   = $request->date('to') ?? CarbonImmutable::parse($from)->endOfMonth();

        return [
            CarbonImmutable::parse($from)->toDateString(),
            CarbonImmutable::parse($to)->toDateString(),
        ];
    }

    /**
     * Narrow a query to whose attendance this person may see.
     *
     * Applied to the list and the counts alike, so a summary card can never
     * total days its owner is not allowed to read.
     */
    private function scopeToReach(Request $request, $q): void
    {
        $user = $request->user();

        if ($user->hasPermission(Permission::ATTENDANCE_VIEW)) {
            return;
        }

        $me = $user->employeeId();

        if ($user->hasPermission(Permission::ATTENDANCE_VIEW_TEAM)) {
            $q->where(fn ($w) => $w->where('employee_id', $me)
                ->orWhereIn('employee_id', Employee::where('reports_to', $me)->select('id')));

            return;
        }

        $q->where('employee_id', $me);
    }

    private function assertReaches(Request $request, int $employeeId): void
    {
        abort_unless(
            app(AttendanceDayPolicy::class)->reaches($request->user(), $employeeId),
            403,
            'That is not your attendance to look at.',
        );
    }

    /**
     * A day, with what was committed to and how it went.
     *
     * Public because the commitment controller returns the same shape after
     * every write — one answer for the screen to redraw from, rather than the
     * client patching its own copy and drifting.
     */
    public function dayWithPlan(AttendanceDay $d, bool $withAudit = false): array
    {
        $payload = $this->row($d, $withAudit);

        $payload['commitments'] = $d->relationLoaded('commitments')
            ? $d->commitments->map(fn ($c) => [
                'id'       => $c->id,
                'title'    => $c->title,
                'kind'     => $c->kind,
                'position' => $c->position,
                'note'     => $c->note,

                // How it went. Null means nobody has said yet, which on a
                // finished day is itself the finding.
                'outcome'  => $c->outcome,
                'reason'   => $c->reason,
                'is_explained' => $c->is_explained,
                'reported_at'  => $c->reported_at?->toIso8601String(),

                // Moved rather than abandoned — a link, so "this has slipped
                // four times" is answerable.
                'carried_to' => $c->relationLoaded('carriedTo') && $c->carriedTo
                    ? [
                        'id' => $c->carriedTo->id,
                        'work_date' => $c->carriedTo->day?->work_date?->toDateString(),
                    ]
                    : null,

                // Optional, and nothing is built on them.
                'starts_at' => $c->starts_at ? substr((string) $c->starts_at, 0, 5) : null,
                'ends_at'   => $c->ends_at ? substr((string) $c->ends_at, 0, 5) : null,

                'task' => $c->relationLoaded('task') && $c->task
                    ? [
                        'id' => $c->task->id,
                        'reference' => $c->task->reference,
                        'status' => $c->task->status,
                        'priority' => $c->task->priority,
                        'is_done' => $c->task->is_done,
                    ]
                    : null,
            ])->values()
            : [];

        /*
         * Promised, delivered, missed, and missed in silence.
         *
         * The last is the one that matters. Work slips, and a manager reacting
         * to slippage is reacting to noise — work that slipped with nobody told
         * is what was previously uncountable.
         */
        $payload['tally'] = $d->commitment_tally;
        $payload['is_reported'] = $d->is_reported;

        return $payload;
    }

    private function row(AttendanceDay $d, bool $withAudit = false): array
    {
        $payload = [
            'id'        => $d->id,
            'work_date' => $d->work_date->toDateString(),
            'status'    => $d->status,

            'employee' => $d->relationLoaded('employee') && $d->employee
                ? [
                    'id' => $d->employee->id,
                    'name' => $d->employee->full_name,
                    'department' => $d->employee->department?->name ?? null,
                ]
                : null,

            /*
             * Planned and actual, side by side. The gap between them is the
             * single most useful thing a hybrid-working report can say, and it
             * is unrecoverable if only one of the two is kept.
             */
            'planned_location' => $d->planned_location,
            'actual_location'  => $d->actual_location,
            'plan_was_kept'    => $d->plan_was_kept,
            'account' => $d->relationLoaded('account') && $d->account
                ? ['id' => $d->account->id, 'name' => $d->account->name] : null,
            'location_note' => $d->location_note,

            'first_clock_in_at' => $d->first_clock_in_at?->toIso8601String(),
            'last_clock_out_at' => $d->last_clock_out_at?->toIso8601String(),
            'worked_minutes'    => $d->worked_minutes,
            'overtime_minutes'  => $d->overtime_minutes,
            'is_clocked_in'     => $d->relationLoaded('punches') ? $d->is_clocked_in : null,

            'is_enablement_day'  => $d->is_enablement_day,
            'enablement_status'  => $d->enablement_status,

            'notes' => $d->notes,

            'punches' => $d->relationLoaded('punches')
                ? $d->punches->map(fn (AttendancePunch $p) => [
                    'id' => $p->id,
                    'in'  => $p->clocked_in_at->toIso8601String(),
                    'out' => $p->clocked_out_at?->toIso8601String(),
                    'minutes' => $p->minutes,
                    'location_type' => $p->location_type,
                    // Typed rather than clocked. Shown, because a report that
                    // cannot tell them apart should not be relied on.
                    'is_manual' => $p->is_manual,
                    'note' => $p->note,
                ])->values()
                : [],
        ];

        if ($withAudit && $d->relationLoaded('audits')) {
            $payload['audits'] = $d->audits->map(fn ($a) => [
                'field' => $a->field,
                'from'  => $a->from_value,
                'to'    => $a->to_value,
                'at'    => $a->changed_at?->toIso8601String(),
                'by'    => $a->changedBy?->name,
            ])->values();
        }

        return $payload;
    }

    private function policyPayload(AttendancePolicy $p): array
    {
        return [
            'work_starts_at' => substr((string) $p->work_starts_at, 0, 5),
            'work_ends_at'   => substr((string) $p->work_ends_at, 0, 5),
            'grace_minutes'  => $p->grace_minutes,
            'standard_hours' => (float) $p->standard_hours,
            'overtime_after_minutes' => $p->overtime_after_minutes,
            'working_days'   => $p->working_days ?: AttendancePolicy::DEFAULT_WORKING_DAYS,
            'plan_ahead_days' => $p->plan_ahead_days,
        ];
    }
}
