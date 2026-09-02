<?php

namespace App\Policies\Procurement;

use App\Models\Procurement\ProcurementDepartmentApprover;
use App\Models\Procurement\ProcurementRequest;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Authorization\Permission;

/**
 * Who may act on the stage a purchase is currently waiting at.
 *
 * The permission is per stage, so holding finance approval does not let
 * somebody sign the department head's line. Structural stages additionally
 * require that the acting person is that department's lead — or somebody
 * delegated to cover it — because a department-head approval from another
 * department's head is not the control it looks like.
 *
 * Extends BasePolicy so every permission is read through the same helper as
 * every other policy — which `PolicyCoverageTest` enforces, so that no policy
 * quietly drifts off the permission catalogue and starts deciding on
 * something else.
 */
class ProcurementApprovalPolicy extends BasePolicy
{
    /** May this user decide the stage currently pending on this request? */
    public function decide(User $user, ProcurementRequest $request): bool
    {
        if ($request->isClosed()) {
            return false;
        }

        $approval = $request->pendingApproval();
        if (! $approval) {
            return false;
        }

        return match ($approval->stage) {
            'finance'    => $this->can($user, Permission::PROCUREMENT_APPROVE_FINANCE),
            'operations' => $this->can($user, Permission::PROCUREMENT_APPROVE_OPERATIONS),
            'executive'  => $this->can($user, Permission::PROCUREMENT_APPROVE_EXECUTIVE),
            default      => $this->can($user, Permission::PROCUREMENT_APPROVE_DEPARTMENT)
                && $this->mayActOnStage($user, $request),
        };
    }

    /** May this user override a budget block? Only finance signs for that. */
    public function overrideBudget(User $user): bool
    {
        return $this->can($user, Permission::PROCUREMENT_APPROVE_FINANCE);
    }

    /**
     * For a structural stage: is this person the expected approver, the
     * department's lead, or somebody covering for them right now?
     */
    private function mayActOnStage(User $user, ProcurementRequest $request): bool
    {
        $approval = $request->pendingApproval();

        // Nobody was resolvable for the stage — a department with no lead, or a
        // request raised without one. The permission alone carries it, rather
        // than stranding the purchase in a queue that belongs to no one.
        if (! $approval || $approval->expected_approver_id === null) {
            return true;
        }

        // A named approver was resolved, so acting in their place has to be
        // earned: be them, or hold cover for their department.
        $employeeId = $user->employeeId();
        if (! $employeeId) {
            return false;
        }

        if ($approval->expected_approver_id === $employeeId) {
            return true;
        }

        return ProcurementDepartmentApprover::where('organization_id', $request->organization_id)
            ->where('employee_id', $employeeId)
            ->when($request->department_id, fn ($q) => $q->where(fn ($w) => $w->whereNull('department_id')->orWhere('department_id', $request->department_id)))
            ->inForce()
            ->exists();
    }
}
