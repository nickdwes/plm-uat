<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Who is about this week, and who is not.
 *
 * The requests table answers "what has been asked for". This answers the
 * question a team leader actually has on a Monday morning — of the people I
 * am responsible for, who is here — which is the same data read by person
 * rather than by request.
 *
 * Built from the employees outwards, not from the leave inwards. Starting
 * from leave rows lists only the people who are away; the useful list is
 * everybody, most of whom are available, and an availability screen that
 * omits the available is a list of absences wearing the wrong title.
 */
class WeekAvailability
{
    /**
     * @param  Collection<int, int>|null  $employeeIds  Null means the whole organisation.
     */
    public function for(int $organizationId, ?Collection $employeeIds, ?Carbon $weekOf = null, array $filters = []): array
    {
        /*
         * Monday to Sunday. The design says "this week", and a week that
         * starts on whatever day you happen to open the page makes two people
         * reading the same screen disagree about what it covers.
         */
        $start = ($weekOf ?? Carbon::now())->copy()->startOfWeek(Carbon::MONDAY);
        $end   = $start->copy()->endOfWeek(Carbon::SUNDAY);

        $people = Employee::query()
            // Employed, not merely 'active' — somebody on probation works
            // here and belongs on the rota.
            ->employed()
            ->when(
                $employeeIds !== null,
                fn ($q) => $q->whereIn('id', $employeeIds),
                fn ($q) => $q->forOrganization($organizationId),
            )
            ->when($filters['search'] ?? null, fn ($q, $term) => $q
                ->where(fn ($w) => $w
                    ->where('first_name', 'ilike', "%{$term}%")
                    ->orWhere('last_name', 'ilike', "%{$term}%")
                    ->orWhere('employee_number', 'ilike', "%{$term}%")
                    ->orWhere('work_email', 'ilike', "%{$term}%")))
            ->with('designation:id,title')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'employee_number', 'designation_id']);

        // One query for the whole week rather than one per person — a listing
        // that costs a round trip per head times out on the team big enough to
        // need one.
        $leave = LeaveRequest::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['approved', 'pending'])
            ->whereIn('employee_id', $people->pluck('id'))
            ->whereDate('from_date', '<=', $end->toDateString())
            ->whereDate('to_date', '>=', $start->toDateString())
            ->with('leaveType:id,name')
            ->get(['id', 'employee_id', 'leave_type_id', 'from_date', 'to_date', 'status', 'scattered_dates', 'is_half_day'])
            ->groupBy('employee_id');

        return [
            'week_start' => $start->toDateString(),
            'week_end'   => $end->toDateString(),
            'people'     => $people->map(function (Employee $e) use ($leave, $start, $end) {
                $theirs = $leave[$e->id] ?? collect();

                $approved = $theirs->firstWhere('status', 'approved');
                $pending  = $theirs->firstWhere('status', 'pending');

                return [
                    'employee_id'     => $e->id,
                    'employee_number' => $e->employee_number,
                    'name'            => trim("{$e->first_name} {$e->last_name}"),
                    'role'            => $e->designation?->title,
                    'status'          => $approved ? 'on_leave' : ($pending ? 'pending' : 'available'),
                    'leave_type'      => ($approved ?? $pending)?->leaveType?->name,
                    'days'            => $this->days($approved ?? $pending, $start, $end),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Which days of this week the request actually covers.
     *
     * A scattered request is not a span: someone taking Monday and Thursday is
     * present on the Tuesday, and rendering it as Mon–Thu tells their manager
     * they are away for four days when they are away for two.
     *
     * @return array<int, string>  Short weekday names, in week order.
     */
    private function days(?LeaveRequest $request, Carbon $start, Carbon $end): array
    {
        if (! $request) {
            return [];
        }

        $names = [];

        if (! empty($request->scattered_dates)) {
            foreach ($request->scattered_dates as $date) {
                $day = Carbon::parse($date);
                if ($day->betweenIncluded($start, $end)) {
                    $names[$day->dayOfWeekIso] = $day->format('D');
                }
            }
        } else {
            $from = Carbon::parse($request->from_date)->max($start);
            $to   = Carbon::parse($request->to_date)->min($end);

            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                $names[$day->dayOfWeekIso] = $day->format('D');
            }
        }

        ksort($names);

        return array_values($names);
    }
}
