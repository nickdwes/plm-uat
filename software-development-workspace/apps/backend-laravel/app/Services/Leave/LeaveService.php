<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Repositories\Leave\LeaveRepository;
use App\Services\BaseService;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class LeaveService extends BaseService
{
    public function __construct(
        LeaveRepository $repository,
        private readonly LeaveCalculatorService $calculator,
    ) {
        parent::__construct($repository);
    }

    /**
     * Paginated list of leave requests for an org, with optional status filter.
     */
    public function list(int $orgId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = LeaveRequest::withoutGlobalScope('organization')
            ->where('organization_id', $orgId)
            ->with(['employee', 'leaveType', 'approvedBy'])
            ->latest();

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Submit a leave request on behalf of an employee.
     */
    public function submit(int $employeeId, int $orgId, array $data): LeaveRequest
    {
        return DB::transaction(function () use ($employeeId, $orgId, $data) {
            $leaveType = LeaveType::findOrFail($data['leave_type_id']);

            $isHalfDay       = (bool) ($data['is_half_day'] ?? false);
            $halfDayPeriod   = $data['half_day_period'] ?? null;
            $scatteredDates  = $data['scattered_dates'] ?? null;

            // Validate feature availability on the leave type.
            if ($isHalfDay && ! $leaveType->allow_half_day) {
                throw new \DomainException("Half-day leave is not allowed for this leave type.");
            }
            if (! empty($scatteredDates) && ! $leaveType->allow_scattered) {
                throw new \DomainException("Scattered-day leave is not allowed for this leave type.");
            }

            // Determine effective from/to dates.
            if (! empty($scatteredDates)) {
                sort($scatteredDates);
                $from = Carbon::parse($scatteredDates[0]);
                $to   = Carbon::parse(end($scatteredDates));
            } elseif ($isHalfDay) {
                $from = Carbon::parse($data['from_date']);
                $to   = $from->copy();
            } else {
                $from = Carbon::parse($data['from_date']);
                $to   = Carbon::parse($data['to_date']);
            }

            $days = $this->calculator->calculate(
                $leaveType,
                $orgId,
                $from->toDateString(),
                $to->toDateString(),
                $isHalfDay,
                ! empty($scatteredDates) ? $scatteredDates : null,
            );

            if ($days <= 0) {
                throw new \DomainException(
                    "The selected date range contains no chargeable leave days for this leave type " .
                    "(weekends and public holidays have been excluded)."
                );
            }

            $year    = $from->year;
            $balance = $this->getOrCreateBalance($employeeId, $leaveType->id, $year);

            $available = (float) $balance->entitled_days + (float) $balance->carried_days
                       - (float) $balance->used_days - (float) $balance->pending_days;

            if ($available < $days) {
                throw new \DomainException(
                    "Insufficient leave balance. Available: {$available}, Requested: {$days}."
                );
            }

            $request = LeaveRequest::create([
                'employee_id'     => $employeeId,
                'organization_id' => $orgId,
                'leave_type_id'   => $leaveType->id,
                'from_date'       => $from,
                'to_date'         => $to,
                'days_requested'  => $days,
                'is_half_day'     => $isHalfDay,
                'half_day_period' => $isHalfDay ? $halfDayPeriod : null,
                'scattered_dates' => ! empty($scatteredDates) ? $scatteredDates : null,
                'reason'          => $data['reason'] ?? null,
                'status'          => $leaveType->requires_approval
                    ? LeaveRequest::STATUS_PENDING
                    : LeaveRequest::STATUS_APPROVED,
            ]);

            if ($request->status === LeaveRequest::STATUS_APPROVED) {
                $balance->increment('used_days', $days);
            } else {
                $balance->increment('pending_days', $days);
            }

            return $request->load('leaveType');
        });
    }

    /**
     * Approve a pending leave request.
     */
    public function approve(int $requestId, int $approverEmployeeId): LeaveRequest
    {
        return DB::transaction(function () use ($requestId, $approverEmployeeId) {
            $request = LeaveRequest::findOrFail($requestId);

            if (! $request->isPending()) {
                throw new \DomainException("Leave request is not pending (current status: {$request->status}).");
            }

            $balance = $this->getOrCreateBalance($request->employee_id, $request->leave_type_id, $request->from_date->year);

            $request->update([
                'status'       => LeaveRequest::STATUS_APPROVED,
                'approved_by'  => $approverEmployeeId,
                'actioned_at'  => now(),
            ]);

            // Move from pending to used.
            $balance->decrement('pending_days', $request->days_requested);
            $balance->increment('used_days', $request->days_requested);

            return $request->fresh()->load(['leaveType', 'approvedBy']);
        });
    }

    /**
     * Reject a pending leave request.
     */
    public function reject(int $requestId, int $approverEmployeeId, string $reason): LeaveRequest
    {
        return DB::transaction(function () use ($requestId, $approverEmployeeId, $reason) {
            $request = LeaveRequest::findOrFail($requestId);

            if (! $request->isPending()) {
                throw new \DomainException("Leave request is not pending (current status: {$request->status}).");
            }

            $balance = $this->getOrCreateBalance($request->employee_id, $request->leave_type_id, $request->from_date->year);

            $request->update([
                'status'           => LeaveRequest::STATUS_REJECTED,
                'approved_by'      => $approverEmployeeId,
                'rejection_reason' => $reason,
                'actioned_at'      => now(),
            ]);

            $balance->decrement('pending_days', $request->days_requested);

            return $request->fresh()->load(['leaveType', 'approvedBy']);
        });
    }

    /**
     * Cancel a pending leave request (by the employee themselves).
     */
    public function cancel(int $requestId, int $employeeId): LeaveRequest
    {
        return DB::transaction(function () use ($requestId, $employeeId) {
            $request = LeaveRequest::where('employee_id', $employeeId)->findOrFail($requestId);

            if (! $request->isPending()) {
                throw new \DomainException("Only pending requests can be cancelled.");
            }

            $balance = $this->getOrCreateBalance($request->employee_id, $request->leave_type_id, $request->from_date->year);

            $request->update(['status' => LeaveRequest::STATUS_CANCELLED]);
            $balance->decrement('pending_days', $request->days_requested);

            return $request->fresh();
        });
    }

    /**
     * Return all leave balances for an employee in a given year.
     */
    public function getBalances(int $employeeId, int $year): array
    {
        return LeaveBalance::where('employee_id', $employeeId)
            ->where('year', $year)
            ->with('leaveType')
            ->get()
            ->map(fn (LeaveBalance $b) => [
                'leave_type'     => $b->leaveType->name,
                'entitled_days'  => (float) $b->entitled_days,
                'used_days'      => (float) $b->used_days,
                'pending_days'   => (float) $b->pending_days,
                'carried_days'   => (float) $b->carried_days,
                'available_days' => $b->available_days,
            ])
            ->toArray();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function getOrCreateBalance(int $employeeId, int $leaveTypeId, int $year): LeaveBalance
    {
        return LeaveBalance::firstOrCreate(
            ['employee_id' => $employeeId, 'leave_type_id' => $leaveTypeId, 'year' => $year],
            ['entitled_days' => 0, 'used_days' => 0, 'pending_days' => 0, 'carried_days' => 0],
        );
    }
}
