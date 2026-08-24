<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\RequiresStepUp;
use App\Models\Asset\Asset;
use App\Models\Attendance\AttendanceDay;
use App\Models\Employee;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Models\Project\Project;
use App\Models\Support\Ticket;
use App\Models\Task\Task;
use App\Services\Performance\CycleAttendance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * One employee's slice of three modules that own their own data.
 *
 * Attendance, payroll and performance each keep their records elsewhere; this
 * reads them for one person so the profile tabs can show them without
 * becoming a second implementation of the module. Nothing here writes.
 */
class EmployeeRecordController extends Controller
{
    use RequiresStepUp;

    /**
     * GET /api/v1/employees/{employee}/attendance
     *
     * The days, and what each of them was.
     */
    public function attendance(Request $request, int $employee): JsonResponse
    {
        $record = $this->readable($employee);

        $from = $request->filled('from')
            ? Carbon::parse($request->string('from')->toString())
            : Carbon::now()->startOfMonth();

        $to = $request->filled('to')
            ? Carbon::parse($request->string('to')->toString())
            : Carbon::now()->endOfMonth();

        $days = AttendanceDay::query()
            ->where('employee_id', $record->id)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('work_date')
            ->get(['id', 'work_date', 'status', 'planned_location', 'actual_location',
                   'first_clock_in_at', 'last_clock_out_at', 'worked_minutes', 'overtime_minutes']);

        return response()->json([
            'data' => [
                'from' => $from->toDateString(),
                'to'   => $to->toDateString(),
                'days' => $days->map(fn ($d) => [
                    'id'            => $d->id,
                    'date'          => $d->work_date?->toDateString(),
                    'status'        => $d->status,
                    'location'      => $d->actual_location ?? $d->planned_location,
                    'clock_in'      => $d->first_clock_in_at?->toISOString(),
                    'clock_out'     => $d->last_clock_out_at?->toISOString(),
                    // Hours, because nobody thinks about a working day in
                    // minutes, and 465 tells you less than 7.75 does.
                    'hours'         => $d->worked_minutes ? round($d->worked_minutes / 60, 2) : null,
                    'overtime_hours' => $d->overtime_minutes ? round($d->overtime_minutes / 60, 2) : null,
                ]),
                'totals' => [
                    'days'     => $days->count(),
                    'present'  => $days->whereIn('status', ['on_time', 'late', 'early', 'no_clock_out'])->count(),
                    'absent'   => $days->where('status', 'absent')->count(),
                    'late'     => $days->where('status', 'late')->count(),
                    'on_leave' => $days->where('status', 'on_leave')->count(),
                    'hours'    => round($days->sum('worked_minutes') / 60, 2),
                ],
            ],
        ]);
    }

    /**
     * GET /api/v1/employees/{employee}/compensation
     *
     * Basic pay and allowances — the one read this whole file exists to
     * protect properly. Everything else on this controller answers "is this
     * viewer allowed to see this" with a permission check; this answers it
     * with a permission check AND proof, asked for again right now, that the
     * session asking is really who it says.
     */
    public function compensation(Request $request, int $employee): JsonResponse
    {
        $record = $this->readable($employee);

        $this->guardSensitive($request, $record);
        $this->requireStepUp($request->user(), 'payroll');

        return response()->json(['data' => [
            'basic_salary'        => (float) $record->basic_salary,
            'house_allowance'     => (float) $record->house_allowance,
            'transport_allowance' => (float) $record->transport_allowance,
            'medical_allowance'   => (float) $record->medical_allowance,
            'other_allowances'    => (float) $record->other_allowances,
            'insurance_relief'    => (float) $record->insurance_relief,
        ]]);
    }

    /**
     * GET /api/v1/employees/{employee}/payslips
     *
     * What this person has been paid, across every run.
     *
     * PayrollService::getPayHistory() already exists and already answers this
     * — it powers GET /payroll/history, self-service-only. This is the same
     * read for one named person rather than the caller, which is what HR
     * needs it for.
     *
     * ── What this replaces ──
     *
     * The previous version joined payroll_run_items to payroll_runs on
     * employee_id and read gross_pay/net_pay/paye/nssf/nhif off the joined
     * row. None of that lines up with the real schema: payroll_run_items has
     * no employee_id at all — it is one row per earning or deduction line on
     * a payslip, not one row per employee per run — and payroll_runs itself
     * already carries employee_id, gross_pay and net_pay directly, with no
     * join required. That version 500'd every time it actually ran; nothing
     * had exercised the path with a real payroll run behind it before now.
     */
    public function payslips(Request $request, int $employee): JsonResponse
    {
        $record = $this->readable($employee);

        $this->guardSensitive($request, $record);
        $this->requireStepUp($request->user(), 'payroll');

        $runs = app(\App\Services\Payroll\PayrollService::class)
            ->getPayHistory($record->id, $request->integer('per_page', 24));

        return response()->json([
            'data' => collect($runs->items())->map(fn ($r) => [
                'run_id'     => $r->id,
                'period'     => $r->period?->name,
                'run_status' => $r->status,
                'gross'      => (float) $r->gross_pay,
                'net'        => (float) $r->net_pay,
                'paye'       => (float) $r->paye,
                // SHIF replaced NHIF; kept under this key so the frontend,
                // which already reads it, needs no change of its own.
                'nhif'       => (float) $r->shif,
                'nssf'       => (float) $r->nssf_tier1 + (float) $r->nssf_tier2,
            ]),
        ]);
    }

    /**
     * GET /api/v1/employees/{employee}/performance
     *
     * Reviews, each with the attendance for the window it covers.
     *
     * The attendance figure is advisory: it is shown for a reviewer to weigh
     * and computes no part of the rating.
     */
    public function performance(Request $request, int $employee, CycleAttendance $attendance): JsonResponse
    {
        $record = $this->readable($employee);
        $orgId  = app('current.organization_id');

        $reviews = PerformanceReview::where('employee_id', $record->id)
            ->with(['cycle:id,name,starts_on,ends_on'])
            ->orderByDesc('review_date')
            ->get();

        $current = PerformanceCycle::where('organization_id', $orgId)
            ->where('is_open', true)
            ->orderByDesc('starts_on')
            ->first();

        return response()->json([
            'data' => [
                'reviews' => $reviews->map(fn (PerformanceReview $r) => [
                    'id'          => $r->id,
                    'cycle'       => $r->cycle?->name ?? $r->review_cycle,
                    'review_date' => $r->review_date?->toDateString(),
                    'rating'      => $r->rating,
                    'comments'    => $r->comments,
                    'outcomes'    => $r->outcomes,
                    // Only where the review points at a cycle. A review with no
                    // recorded window has no window to measure.
                    'attendance'  => $r->cycle
                        ? $attendance->for($record->id, $r->cycle)
                        : null,
                ]),
                // So the tab can show where somebody stands now, not only at
                // the last review.
                'current' => $current
                    ? ['cycle' => $current->name] + $attendance->for($record->id, $current)
                    : null,
            ],
        ]);
    }

    /**
     * GET /api/v1/employees/{employee}/tasks
     *
     * What this person is holding, across every project.
     */
    public function tasks(Request $request, int $employee): JsonResponse
    {
        $record = $this->readable($employee);

        $tasks = Task::where('assigned_to', $record->id)
            ->with('project:id,name,reference')
            ->orderByRaw("case status when 'in_progress' then 0 when 'todo' then 1 else 2 end")
            ->orderBy('deadline')
            ->get(['id', 'title', 'status', 'priority', 'deadline', 'project_id', 'started_on']);

        return response()->json([
            'data' => $tasks->map(fn (Task $t) => [
                'id'       => $t->id,
                'title'    => $t->title,
                'status'   => $t->status,
                'priority' => $t->priority,
                'deadline' => $t->deadline?->toDateString(),
                'project'  => $t->project ? ['id' => $t->project->id, 'name' => $t->project->name] : null,
            ]),
        ]);
    }

    /**
     * GET /api/v1/employees/{employee}/projects
     *
     * What they lead, and what they deliver on — the same predicate the
     * project listing already uses to answer "my projects", read for one
     * person instead of the signed-in one.
     */
    public function projects(Request $request, int $employee): JsonResponse
    {
        $record = $this->readable($employee);

        $projects = Project::where(fn ($w) => $w->where('project_lead_id', $record->id)
                ->orWhereHas('team', fn ($t) => $t->where('employee_id', $record->id)))
            ->with('account:id,name')
            ->orderByDesc('id')
            ->get(['id', 'name', 'reference', 'status', 'priority', 'deadline', 'account_id', 'project_lead_id']);

        return response()->json([
            'data' => $projects->map(fn (Project $p) => [
                'id'       => $p->id,
                'name'     => $p->name,
                'account'  => $p->account?->name,
                'status'   => $p->status,
                'priority' => $p->priority,
                'deadline' => $p->deadline?->toDateString(),
                // Named rather than left to be inferred — leading a project
                // and delivering on one are different facts about somebody.
                'role'     => $p->project_lead_id === $record->id ? 'lead' : 'team',
            ]),
        ]);
    }

    /**
     * GET /api/v1/employees/{employee}/support-tickets
     *
     * Tickets this person is working, and tickets they raised.
     *
     * Both sides read `assigned_to` / `requester_user_id` on the ticket, which
     * point at a login (`users`), not at this employee record directly — so
     * the match goes through whichever account this person signs in with. A
     * person with no linked account cannot appear on either side, and the
     * empty result says so rather than looking like nobody has ever touched a
     * ticket.
     */
    public function supportTickets(Request $request, int $employee): JsonResponse
    {
        $record = $this->readable($employee);

        if (! $record->user_id) {
            return response()->json([
                'data' => [], 'note' => 'This person has no linked login, so no ticket can be matched to them.',
            ]);
        }

        $tickets = Ticket::where(fn ($w) => $w->where('assigned_to', $record->user_id)
                ->orWhere('requester_user_id', $record->user_id))
            ->with(['account:id,name', 'status:id,name,state', 'priority:id,name'])
            ->orderByDesc('created_at')
            ->get(['id', 'reference', 'subject', 'account_id', 'ticket_status_id', 'priority_id',
                   'assigned_to', 'requester_user_id', 'created_at']);

        return response()->json([
            'data' => $tickets->map(fn (Ticket $t) => [
                'id'        => $t->id,
                'reference' => $t->reference,
                'subject'   => $t->subject,
                'account'   => $t->account?->name,
                'status'    => $t->status?->name,
                'priority'  => $t->priority?->name,
                'role'      => $t->assigned_to === $record->user_id ? 'assigned' : 'raised',
                'created_at' => $t->created_at?->toISOString(),
            ]),
        ]);
    }

    /**
     * GET /api/v1/employees/{employee}/assets
     *
     * What is issued to them, and what they are the custodian of — different
     * facts, so each row says which it is.
     */
    public function assets(Request $request, int $employee): JsonResponse
    {
        $record = $this->readable($employee);

        $assets = Asset::where(fn ($w) => $w->where('assigned_to', $record->id)
                ->orWhere('custodian_id', $record->id))
            ->orderByDesc('assigned_date')
            ->get(['id', 'name', 'asset_tag', 'serial_number', 'model', 'status',
                   'assigned_date', 'assigned_to', 'custodian_id']);

        return response()->json([
            'data' => $assets->map(fn (Asset $a) => [
                'id'            => $a->id,
                'name'          => $a->name,
                'tag'           => $a->asset_tag,
                'serial'        => $a->serial_number,
                'model'         => $a->model,
                'status'        => $a->status,
                'assigned_date' => $a->assigned_date?->toDateString(),
                'role'          => $a->assigned_to === $record->id ? 'assigned' : 'custodian',
            ]),
        ]);
    }

    /**
     * Pay is gated the way pay is gated everywhere else — the permission, an
     * HR role, or looking at your own record.
     */
    private function guardSensitive(Request $request, Employee $record): void
    {
        abort_unless(
            \App\Services\HR\PeopleReport::maySeeSensitive($request->user(), app('current.organization_id'))
                || $request->user()?->employee?->id === $record->id,
            403,
            'This needs permission to see sensitive employee data.',
        );
    }

    /** The employee, if this viewer may see them at all. */
    private function readable(int $employee): Employee
    {
        $orgId = app('current.organization_id');

        $record = Employee::when($orgId, fn ($q, $org) => $q->forOrganization($org))
            ->findOrFail($employee);

        $this->authorize('view', $record);

        return $record;
    }
}
