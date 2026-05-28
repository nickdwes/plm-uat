<?php

namespace App\Services\Roster;

use App\Models\Employee;
use App\Models\Roster\RosterAssignment;
use App\Models\Roster\RosterConfiguration;
use App\Models\Roster\RosterSchedule;
use App\Models\Roster\RosterShiftGroup;
use App\Models\Roster\RosterShiftSwapRequest;
use App\Models\Roster\RosterShiftType;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RosterService
{
    // ── General Configuration ─────────────────────────────────────────────────

    public function getConfig(int $orgId): RosterConfiguration
    {
        return RosterConfiguration::firstOrCreate(
            ['organization_id' => $orgId],
            [
                'primary_detail_shown'      => RosterConfiguration::DETAIL_TOTAL_SHIFT_HOURS,
                'swap_shifts_enabled'        => false,
                'manager_approval_required'  => true,
            ]
        );
    }

    public function updateConfig(int $orgId, array $data): RosterConfiguration
    {
        $config = $this->getConfig($orgId);
        $config->update($data);

        return $config->fresh();
    }

    // ── Shift Groups ──────────────────────────────────────────────────────────

    public function listShiftGroups(int $orgId): Collection
    {
        return RosterShiftGroup::where('organization_id', $orgId)
            ->orderBy('name')
            ->get();
    }

    public function storeShiftGroup(int $orgId, array $data): RosterShiftGroup
    {
        return RosterShiftGroup::create(array_merge($data, ['organization_id' => $orgId]));
    }

    public function updateShiftGroup(RosterShiftGroup $group, array $data): RosterShiftGroup
    {
        $group->update($data);

        return $group->fresh();
    }

    public function destroyShiftGroup(RosterShiftGroup $group): void
    {
        $group->delete();
    }

    // ── Shift Types ───────────────────────────────────────────────────────────

    public function listShiftTypes(int $orgId, array $filters = []): Collection
    {
        $query = RosterShiftType::where('organization_id', $orgId)
            ->with('shiftGroup')
            ->orderBy('name');

        if (isset($filters['shift_group_id'])) {
            $query->where('shift_group_id', $filters['shift_group_id']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        return $query->get();
    }

    public function storeShiftType(int $orgId, array $data): RosterShiftType
    {
        $computed = $this->computeDuration($data['start_time'], $data['end_time']);

        return RosterShiftType::create(array_merge($data, [
            'organization_id'        => $orgId,
            'duration_minutes'       => $computed['duration_minutes'],
            'is_overnight'           => $computed['is_overnight'],
            'break_duration_minutes' => $data['break_duration_minutes'] ?? 0,
        ]));
    }

    public function updateShiftType(RosterShiftType $type, array $data): RosterShiftType
    {
        $start = $data['start_time'] ?? $type->start_time;
        $end   = $data['end_time']   ?? $type->end_time;

        $computed = $this->computeDuration($start, $end);

        $type->update(array_merge($data, [
            'duration_minutes'       => $computed['duration_minutes'],
            'is_overnight'           => $computed['is_overnight'],
            'break_duration_minutes' => $data['break_duration_minutes'] ?? $type->break_duration_minutes ?? 0,
        ]));

        return $type->fresh(['shiftGroup']);
    }

    public function destroyShiftType(RosterShiftType $type): void
    {
        $type->delete();
    }

    // ── Roster Schedules ──────────────────────────────────────────────────────

    public function listSchedules(int $orgId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = RosterSchedule::where('organization_id', $orgId)
            ->with('creator')
            ->latest('start_date');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }

    public function storeSchedule(int $orgId, int $createdBy, array $data): RosterSchedule
    {
        return RosterSchedule::create(array_merge($data, [
            'organization_id' => $orgId,
            'created_by'      => $createdBy,
            'status'          => RosterSchedule::STATUS_DRAFT,
        ]));
    }

    public function updateSchedule(RosterSchedule $schedule, array $data): RosterSchedule
    {
        abort_if(
            $schedule->isPublished(),
            422,
            'Published schedules cannot be edited. Un-publish or duplicate the schedule first.'
        );

        $schedule->update($data);

        return $schedule->fresh();
    }

    public function publishSchedule(RosterSchedule $schedule): RosterSchedule
    {
        abort_if($schedule->isPublished(), 422, 'Schedule is already published.');

        $schedule->update([
            'status'       => RosterSchedule::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        return $schedule->fresh();
    }

    public function destroySchedule(RosterSchedule $schedule): void
    {
        abort_if(
            $schedule->isPublished(),
            422,
            'Published schedules cannot be deleted.'
        );

        $schedule->delete();
    }

    // ── Roster Assignments ────────────────────────────────────────────────────

    public function getAssignments(RosterSchedule $schedule, array $filters = []): Collection
    {
        $query = $schedule->assignments()
            ->with(['employee', 'shiftType', 'shiftType.shiftGroup'])
            ->orderBy('assignment_date')
            ->orderBy('employee_id');

        if (isset($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }

        if (isset($filters['date'])) {
            $query->whereDate('assignment_date', $filters['date']);
        }

        return $query->get();
    }

    public function assignShift(RosterSchedule $schedule, array $data): RosterAssignment
    {
        // Conflict check: one slot per employee per day.
        $exists = RosterAssignment::where('schedule_id', $schedule->id)
            ->where('employee_id', $data['employee_id'])
            ->whereDate('assignment_date', $data['assignment_date'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'assignment_date' => 'This employee already has a shift assigned on that date in this schedule.',
            ]);
        }

        return RosterAssignment::create(array_merge($data, ['schedule_id' => $schedule->id]));
    }

    /**
     * Bulk-assign the same shift to multiple employees on multiple dates.
     *
     * @param  array{employee_ids: int[], shift_type_id: ?int, dates: string[], type: string, notes: ?string}  $data
     * @return array{created: int, skipped: int}
     */
    public function bulkAssign(RosterSchedule $schedule, array $data): array
    {
        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($schedule, $data, &$created, &$skipped) {
            foreach ($data['employee_ids'] as $employeeId) {
                foreach ($data['dates'] as $date) {
                    $exists = RosterAssignment::where('schedule_id', $schedule->id)
                        ->where('employee_id', $employeeId)
                        ->whereDate('assignment_date', $date)
                        ->exists();

                    if ($exists) {
                        $skipped++;
                        continue;
                    }

                    RosterAssignment::create([
                        'schedule_id'     => $schedule->id,
                        'employee_id'     => $employeeId,
                        'shift_type_id'   => $data['shift_type_id'] ?? null,
                        'assignment_date' => $date,
                        'type'            => $data['type'] ?? RosterAssignment::TYPE_SHIFT,
                        'notes'           => $data['notes'] ?? null,
                    ]);

                    $created++;
                }
            }
        });

        return compact('created', 'skipped');
    }

    public function removeAssignment(RosterAssignment $assignment): void
    {
        $assignment->delete();
    }

    // ── Shift Swap Requests ───────────────────────────────────────────────────

    public function listSwapRequests(int $orgId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = RosterShiftSwapRequest::where('organization_id', $orgId)
            ->with([
                'requester',
                'requestedEmployee',
                'requesterAssignment.shiftType',
                'requestedAssignment.shiftType',
                'approvedByEmployee',
            ])
            ->latest();

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['employee_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('requester_id', $filters['employee_id'])
                  ->orWhere('requested_employee_id', $filters['employee_id']);
            });
        }

        return $query->paginate($perPage);
    }

    public function createSwapRequest(int $orgId, int $requesterId, array $data): RosterShiftSwapRequest
    {
        // Verify the requester owns the assignment.
        $requesterAssignment = RosterAssignment::findOrFail($data['requester_assignment_id']);

        abort_unless(
            $requesterAssignment->employee_id === $requesterId,
            403,
            'You can only request a swap for your own shifts.'
        );

        // Verify the target assignment belongs to the requested employee.
        if (isset($data['requested_assignment_id'])) {
            $targetAssignment = RosterAssignment::findOrFail($data['requested_assignment_id']);

            abort_unless(
                $targetAssignment->employee_id === $data['requested_employee_id'],
                422,
                'The target assignment does not belong to the requested employee.'
            );
        }

        return RosterShiftSwapRequest::create(array_merge($data, [
            'organization_id' => $orgId,
            'requester_id'    => $requesterId,
            'status'          => RosterShiftSwapRequest::STATUS_PENDING,
        ]));
    }

    public function respondToSwapRequest(
        RosterShiftSwapRequest $swap,
        int $respondingEmployeeId,
        bool $accept,
        ?string $notes = null
    ): RosterShiftSwapRequest {
        abort_unless(
            $swap->requested_employee_id === $respondingEmployeeId,
            403,
            'Only the requested employee can respond to this swap.'
        );

        abort_unless($swap->isPending(), 422, 'This swap request is no longer pending.');

        $status = $accept
            ? RosterShiftSwapRequest::STATUS_ACCEPTED
            : RosterShiftSwapRequest::STATUS_REJECTED;

        $swap->update([
            'status'        => $status,
            'responder_notes' => $notes,
            'responded_at'  => now(),
        ]);

        return $swap->fresh();
    }

    public function approveSwapRequest(
        RosterShiftSwapRequest $swap,
        int $managerId,
        ?string $notes = null
    ): RosterShiftSwapRequest {
        abort_unless(
            $swap->status === RosterShiftSwapRequest::STATUS_ACCEPTED,
            422,
            'Swap must be accepted by the co-worker before manager approval.'
        );

        DB::transaction(function () use ($swap, $managerId, $notes) {
            // Perform the actual shift exchange.
            $requesterAssignment = $swap->requesterAssignment;
            $requestedAssignment = $swap->requestedAssignment;

            if ($requesterAssignment && $requestedAssignment) {
                $requesterShiftId = $requesterAssignment->shift_type_id;
                $requestedShiftId = $requestedAssignment->shift_type_id;

                $requesterAssignment->update(['shift_type_id' => $requestedShiftId]);
                $requestedAssignment->update(['shift_type_id' => $requesterShiftId]);
            }

            $swap->update([
                'status'       => RosterShiftSwapRequest::STATUS_APPROVED,
                'manager_notes' => $notes,
                'approved_by'  => $managerId,
                'approved_at'  => now(),
            ]);
        });

        return $swap->fresh();
    }

    public function cancelSwapRequest(RosterShiftSwapRequest $swap, int $employeeId): RosterShiftSwapRequest
    {
        abort_unless(
            $swap->requester_id === $employeeId,
            403,
            'Only the requester can cancel a swap request.'
        );

        abort_unless($swap->isPending(), 422, 'Only pending swap requests can be cancelled.');

        $swap->update(['status' => RosterShiftSwapRequest::STATUS_CANCELLED]);

        return $swap->fresh();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Calculate gross shift duration and detect overnight shifts.
     *
     * @param  string  $startTime  e.g. "08:00" or "22:00"
     * @param  string  $endTime    e.g. "17:00" or "06:00"
     * @return array{duration_minutes: int, is_overnight: bool}
     */
    private function computeDuration(string $startTime, string $endTime): array
    {
        [$sh, $sm] = array_map('intval', explode(':', $startTime));
        [$eh, $em] = array_map('intval', explode(':', $endTime));

        $startMinutes = $sh * 60 + $sm;
        $endMinutes   = $eh * 60 + $em;

        $isOvernight = $endMinutes <= $startMinutes;
        $duration    = $isOvernight
            ? (24 * 60 - $startMinutes) + $endMinutes
            : $endMinutes - $startMinutes;

        return ['duration_minutes' => $duration, 'is_overnight' => $isOvernight];
    }
}
