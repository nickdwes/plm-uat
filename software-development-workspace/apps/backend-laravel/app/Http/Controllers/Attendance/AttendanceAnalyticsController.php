<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Attendance\AttendanceCommitment;
use App\Models\Attendance\AttendanceDay;
use App\Models\Employee;
use App\Support\Authorization\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What the workforce actually did.
 *
 * The requirement asks for attendance, location, productivity and enablement in
 * one place. The temptation is a wall of figures; the discipline is deciding
 * which of them anybody acts on.
 *
 * Two principles run through this.
 *
 * Rates rather than counts wherever people are compared. Somebody who was on
 * leave for half the period has fewer of everything, and a table sorted by raw
 * totals quietly ranks people by how much they were at work.
 *
 * `unexplained` leads every view. Work slips — a manager reacting to slippage
 * is reacting to noise. Work that slipped with nobody told is the thing worth
 * a conversation, and it is the number this module exists to produce.
 */
class AttendanceAnalyticsController extends Controller
{
    /**
     * A row per person, over a window.
     *
     * The table HR asks for: who, where, how punctual, how much they said they
     * would do, how much landed, and how much went unexplained.
     */
    public function people(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceDay::class);

        [$from, $to] = $this->window($request);
        $reach = $this->reachableEmployeeIds($request);

        $days = AttendanceDay::query()
            ->whereBetween('work_date', [$from, $to])
            ->when($reach !== null, fn ($q) => $q->whereIn('employee_id', $reach))
            ->selectRaw('
                employee_id,
                count(*) filter (where status in (?, ?, ?)) as worked,
                count(*) filter (where status = ?) as on_time,
                count(*) filter (where status = ?) as late,
                count(*) filter (where status = ?) as absent,
                count(*) filter (where status = ?) as on_leave,
                count(*) filter (where actual_location = ?) as office,
                count(*) filter (where actual_location = ?) as home,
                count(*) filter (where actual_location = ?) as customer_site,
                count(*) filter (where is_enablement_day and enablement_status = ?) as enablement,
                sum(worked_minutes) as minutes
            ', [
                'on_time', 'late', 'early', 'on_time', 'late', 'absent', 'on_leave',
                'office', 'home', 'customer_site', 'approved',
            ])
            ->groupBy('employee_id')
            ->get()
            ->keyBy('employee_id');

        $work = $this->commitmentsByEmployee($from, $to, $reach);

        $employees = Employee::whereIn('id', $days->keys()->merge($work->keys())->unique())
            ->with('department')->get();

        $rows = $employees->map(function (Employee $e) use ($days, $work) {
            $d = $days->get($e->id);
            $w = $work->get($e->id);

            $worked   = (int) ($d->worked ?? 0);
            $promised = (int) ($w->promised ?? 0);

            return [
                'employee'   => ['id' => $e->id, 'name' => $e->full_name],
                'department' => $e->department?->name,

                'attendance' => [
                    'worked'   => $worked,
                    'on_time'  => (int) ($d->on_time ?? 0),
                    'late'     => (int) ($d->late ?? 0),
                    'absent'   => (int) ($d->absent ?? 0),
                    'on_leave' => (int) ($d->on_leave ?? 0),
                    // A rate, because somebody on leave half the period has
                    // fewer of everything and a raw count ranks people by how
                    // much they were at work.
                    'punctuality' => $worked ? round(100 * (int) ($d->on_time ?? 0) / $worked) : null,
                    'hours' => $d?->minutes ? round($d->minutes / 60, 1) : null,
                ],

                'location' => [
                    'office'        => (int) ($d->office ?? 0),
                    'home'          => (int) ($d->home ?? 0),
                    'customer_site' => (int) ($d->customer_site ?? 0),
                ],

                'work' => [
                    'promised'    => $promised,
                    'done'        => (int) ($w->done ?? 0),
                    'missed'      => (int) ($w->missed ?? 0),
                    'unexplained' => (int) ($w->unexplained ?? 0),
                    'completion'  => $promised ? round(100 * (int) ($w->done ?? 0) / $promised) : null,
                ],

                'enablement_days' => (int) ($d->enablement ?? 0),
            ];
        });

        return response()->json([
            // Sorted by what needs a conversation, not alphabetically. A report
            // people have to scan for the problem is one they stop opening.
            'data'  => $rows->sortByDesc(fn ($r) => $r['work']['unexplained'])->values(),
            'range' => ['from' => $from, 'to' => $to],
        ]);
    }

    /**
     * Where the work happened, week by week.
     *
     * The hybrid-working question: whether the office is filling or emptying,
     * and how much is spent at customers. A trend rather than a total, because
     * a single percentage tells you nothing about direction.
     */
    public function trends(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceDay::class);

        [$from, $to] = $this->window($request);
        $reach = $this->reachableEmployeeIds($request);

        $weeks = AttendanceDay::query()
            ->whereBetween('work_date', [$from, $to])
            ->whereNotNull('actual_location')
            ->when($reach !== null, fn ($q) => $q->whereIn('employee_id', $reach))
            ->selectRaw("
                to_char(date_trunc('week', work_date), 'YYYY-MM-DD') as week,
                count(*) filter (where actual_location = ?) as office,
                count(*) filter (where actual_location = ?) as home,
                count(*) filter (where actual_location = ?) as customer_site,
                count(*) as total
            ", ['office', 'home', 'customer_site'])
            ->groupBy('week')->orderBy('week')->get();

        /*
         * Plan against actual, over the whole window.
         *
         * "Said office, worked from home" is the number that tells you whether
         * the location reporting is worth anything at all — a plan nobody keeps
         * is a plan nobody should be using for desk planning.
         */
        $plans = AttendanceDay::query()
            ->whereBetween('work_date', [$from, $to])
            ->whereNotNull('planned_location')->whereNotNull('actual_location')
            ->when($reach !== null, fn ($q) => $q->whereIn('employee_id', $reach))
            ->selectRaw('
                count(*) as stated,
                count(*) filter (where planned_location = actual_location) as kept
            ')->first();

        return response()->json([
            'weeks' => $weeks->map(fn ($w) => [
                'week'  => $w->week,
                'office' => (int) $w->office,
                'home'   => (int) $w->home,
                'customer_site' => (int) $w->customer_site,
                'total'  => (int) $w->total,
            ]),
            'plan_adherence' => [
                'stated' => (int) ($plans->stated ?? 0),
                'kept'   => (int) ($plans->kept ?? 0),
                'rate'   => ($plans->stated ?? 0) ? round(100 * $plans->kept / $plans->stated) : null,
            ],
            'range' => ['from' => $from, 'to' => $to],
        ]);
    }

    /**
     * Where the promised work was pointed, and how the enablement day is used.
     *
     * By project and by customer, because "tasks by project or customer" is
     * asked for by name — and because a team spending four fifths of its
     * committed days on one account is a fact worth surfacing before the
     * account leaves.
     */
    public function distribution(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceDay::class);

        [$from, $to] = $this->window($request);
        $reach = $this->reachableEmployeeIds($request);

        $dayIds = AttendanceDay::query()
            ->whereBetween('work_date', [$from, $to])
            ->when($reach !== null, fn ($q) => $q->whereIn('employee_id', $reach))
            ->select('id');

        $byProject = AttendanceCommitment::query()
            ->whereIn('attendance_day_id', $dayIds)
            ->join('tasks', 'tasks.id', '=', 'attendance_commitments.task_id')
            ->leftJoin('projects', 'projects.id', '=', 'tasks.project_id')
            ->selectRaw("coalesce(projects.name, 'No project') as label, count(*) as n")
            ->groupBy('label')->orderByDesc('n')->limit(15)->get();

        $byAccount = AttendanceCommitment::query()
            ->whereIn('attendance_day_id', $dayIds)
            ->join('tasks', 'tasks.id', '=', 'attendance_commitments.task_id')
            ->leftJoin('accounts', 'accounts.id', '=', 'tasks.account_id')
            ->whereNotNull('tasks.account_id')
            ->selectRaw('accounts.name as label, count(*) as n')
            ->groupBy('label')->orderByDesc('n')->limit(15)->get();

        /*
         * What the enablement day is actually spent on.
         *
         * The requirement asks for "enablement activities undertaken", and an
         * approved day with nothing recorded against it is the answer nobody
         * wants but everybody needs — a day off by another name.
         */
        $enablementDays = AttendanceDay::query()
            ->whereBetween('work_date', [$from, $to])
            ->where('is_enablement_day', true)->where('enablement_status', 'approved')
            ->when($reach !== null, fn ($q) => $q->whereIn('employee_id', $reach))
            ->pluck('id');

        $enablementWork = AttendanceCommitment::whereIn('attendance_day_id', $enablementDays)
            ->selectRaw('count(*) as promised, count(*) filter (where outcome = ?) as done', ['done'])
            ->first();

        $daysWithNothing = $enablementDays->isEmpty() ? 0 : $enablementDays->count()
            - AttendanceCommitment::whereIn('attendance_day_id', $enablementDays)
                ->distinct()->count('attendance_day_id');

        return response()->json([
            'by_project' => $byProject->map(fn ($r) => ['label' => $r->label, 'count' => (int) $r->n]),
            'by_account' => $byAccount->map(fn ($r) => ['label' => $r->label, 'count' => (int) $r->n]),
            'enablement' => [
                'days'      => $enablementDays->count(),
                'promised'  => (int) ($enablementWork->promised ?? 0),
                'done'      => (int) ($enablementWork->done ?? 0),
                // An approved day with nothing recorded against it.
                'days_with_nothing_recorded' => max(0, $daysWithNothing),
            ],
            'range' => ['from' => $from, 'to' => $to],
        ]);
    }

    // ── Plumbing ──────────────────────────────────────────────────────────

    private function commitmentsByEmployee(string $from, string $to, ?array $reach)
    {
        return AttendanceCommitment::query()
            ->join('attendance_days', 'attendance_days.id', '=', 'attendance_commitments.attendance_day_id')
            ->whereBetween('attendance_days.work_date', [$from, $to])
            ->when($reach !== null, fn ($q) => $q->whereIn('attendance_days.employee_id', $reach))
            ->selectRaw('
                attendance_days.employee_id,
                count(*) as promised,
                count(*) filter (where outcome = ?) as done,
                count(*) filter (where outcome = ?) as missed,
                count(*) filter (
                    where attendance_days.work_date < current_date
                      and (outcome is null or (outcome = ? and reason is null))
                ) as unexplained
            ', ['done', 'not_done', 'not_done'])
            ->groupBy('attendance_days.employee_id')
            ->get()
            ->keyBy('employee_id');
    }

    /**
     * Whose attendance this person may read, or null for everybody.
     *
     * Null rather than a list of every id in the organisation: a report that
     * builds a ten-thousand-item `IN` clause to say "no restriction" is a
     * report that gets slower as the company grows.
     */
    private function reachableEmployeeIds(Request $request): ?array
    {
        $user = $request->user();

        if ($user->hasPermission(Permission::ATTENDANCE_VIEW)) {
            return null;
        }

        $me = $user->employeeId();

        if ($user->hasPermission(Permission::ATTENDANCE_VIEW_TEAM)) {
            return Employee::where('reports_to', $me)->pluck('id')->push($me)->all();
        }

        return [$me];
    }

    /** The last four weeks by default, which is the span people ask about. */
    private function window(Request $request): array
    {
        $from = $request->date('from') ?? now()->subWeeks(4)->startOfWeek();
        $to   = $request->date('to') ?? now();

        return [
            CarbonImmutable::parse($from)->toDateString(),
            CarbonImmutable::parse($to)->toDateString(),
        ];
    }
}
