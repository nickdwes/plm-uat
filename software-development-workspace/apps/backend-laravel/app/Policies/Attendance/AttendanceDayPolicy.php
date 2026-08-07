<?php

namespace App\Policies\Attendance;

use App\Models\Attendance\AttendanceDay;
use App\Models\Employee;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Authorization\Permission;

/**
 * Who may see whose attendance.
 *
 * Attendance is where somebody was and when, every day — the most personal
 * record this system holds outside payroll. So the scopes are the design: your
 * own, your team's, or everybody's, and nothing in between.
 */
class AttendanceDayPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny(
            $user,
            Permission::ATTENDANCE_VIEW,
            Permission::ATTENDANCE_VIEW_TEAM,
            Permission::ATTENDANCE_VIEW_OWN,
        );
    }

    public function view(User $user, AttendanceDay $day): bool
    {
        return $this->inCurrentOrg($day) && $this->reaches($user, $day->employee_id);
    }

    /**
     * Recording is about your own day.
     *
     * Filling in somebody else's is `manage`, because a clock-in entered on
     * another person's behalf is a claim about where they were.
     */
    public function record(User $user, AttendanceDay $day): bool
    {
        if (! $this->inCurrentOrg($day)) {
            return false;
        }

        if ($day->employee_id === $user->employeeId()) {
            return $this->can($user, Permission::ATTENDANCE_RECORD);
        }

        return $this->can($user, Permission::ATTENDANCE_MANAGE);
    }

    public function update(User $user, AttendanceDay $day): bool
    {
        return $this->record($user, $day);
    }

    /** Whether this person's attendance is within reach at all. */
    public function reaches(User $user, ?int $employeeId): bool
    {
        if ($employeeId === null) {
            return false;
        }

        if ($this->can($user, Permission::ATTENDANCE_VIEW)) {
            return true;
        }

        $me = $user->employeeId();

        if ($employeeId === $me) {
            return $this->canAny($user, Permission::ATTENDANCE_VIEW_OWN, Permission::ATTENDANCE_RECORD);
        }

        // A manager reaches the people who report to them, and only them —
        // not their manager's other reports, and not sideways.
        return $this->can($user, Permission::ATTENDANCE_VIEW_TEAM)
            && Employee::whereKey($employeeId)->where('reports_to', $me)->exists();
    }
}
