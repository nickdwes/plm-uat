<?php

namespace App\Policies\Travel;

use App\Models\Travel\TravelRequest;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Authorization\Permission;

/**
 * Authorization for travel requests.
 *
 * The four approval stages are four different authorities. Collapsing them
 * into one permission would let anyone who can approve a trip also release the
 * money, which is the separation the chain exists to enforce.
 */
class TravelRequestPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny(
            $user,
            Permission::TRAVEL_VIEW,
            Permission::TRAVEL_VIEW_TEAM,
            Permission::TRAVEL_VIEW_OWN,
        );
    }

    public function view(User $user, TravelRequest $request): bool
    {
        if (! $this->inCurrentOrg($request)) {
            return false;
        }

        if ($this->can($user, Permission::TRAVEL_VIEW)) {
            return true;
        }

        // The traveller always sees their own, including while it is being
        // approved — not knowing where your own claim has reached is what
        // makes people chase approvers by email instead.
        if ($request->employee_id === $user->employeeId()) {
            return $this->can($user, Permission::TRAVEL_VIEW_OWN);
        }

        // An approver must be able to read what they are being asked to decide
        // on, regardless of any broader view permission.
        if ($this->isExpectedApprover($user, $request)) {
            return true;
        }

        return $this->can($user, Permission::TRAVEL_VIEW_TEAM)
            && $request->department_id !== null
            && $request->department_id === $this->departmentOf($user);
    }

    public function create(User $user): bool
    {
        return $this->can($user, Permission::TRAVEL_SUBMIT);
    }

    public function update(User $user, TravelRequest $request): bool
    {
        if (! $this->inCurrentOrg($request) || ! $request->isEditable()) {
            return false;
        }

        return $request->employee_id === $user->employeeId()
            ? $this->can($user, Permission::TRAVEL_SUBMIT)
            : $this->can($user, Permission::TRAVEL_MANAGE);
    }

    public function submit(User $user, TravelRequest $request): bool
    {
        return $this->update($user, $request);
    }

    /**
     * Whether this user may decide the stage the request is waiting on.
     *
     * Each stage is checked against its own permission, and the structural
     * stages additionally require the user to be the person the request was
     * routed to. Holding `travel.approve` is not enough to approve any
     * department's travel — only the one you lead.
     */
    public function decide(User $user, TravelRequest $request): bool
    {
        if (! $this->inCurrentOrg($request)) {
            return false;
        }

        $pending = $request->approvals()->where('status', 'pending')->orderBy('sequence')->first();

        if (! $pending) {
            return false;
        }

        return match ($pending->stage) {
            'department_head', 'function_head' =>
                $this->can($user, Permission::TRAVEL_APPROVE)
                && $pending->expected_approver_id !== null
                && $pending->expected_approver_id === $user->employeeId(),

            'finance'   => $this->can($user, Permission::TRAVEL_ASSESS_FINANCE),
            'executive' => $this->can($user, Permission::TRAVEL_APPROVE_EXECUTIVE),

            default => false,
        };
    }

    /** Destinations, expense categories, rate card and stage configuration. */
    public function manageReferenceData(User $user): bool
    {
        return $this->can($user, Permission::TRAVEL_MANAGE_RATES);
    }

    private function isExpectedApprover(User $user, TravelRequest $request): bool
    {
        $employeeId = $user->employeeId();

        return $employeeId !== null
            && $request->approvals()->where('expected_approver_id', $employeeId)->exists();
    }

    private function departmentOf(User $user): ?int
    {
        return $user->employee?->department_id;
    }
}
