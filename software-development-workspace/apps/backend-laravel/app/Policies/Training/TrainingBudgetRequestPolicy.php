<?php

namespace App\Policies\Training;

use App\Models\Training\TrainingBudgetRequest;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Authorization\Permission;

/**
 * Two distinct approval abilities rather than one generic "approve", because
 * who may act depends on which stage the request is actually in: the
 * resolved manager (or an HR override) at pending_manager, a Finance-permission
 * holder at pending_finance. Neither one is a substitute for the other —
 * that separation is the entire point of the two-stage design.
 */
class TrainingBudgetRequestPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, Permission::TRAINING_VIEW, Permission::TRAINING_MANAGE)
            || $this->isCompanyHR($user);
    }

    public function view(User $user, TrainingBudgetRequest $request): bool
    {
        return $this->inCurrentOrg($request) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->can($user, Permission::TRAINING_MANAGE) || $this->isCompanyHR($user);
    }

    public function approveAsManager(User $user, TrainingBudgetRequest $request): bool
    {
        return $this->inCurrentOrg($request)
            && ($request->manager_id === $user->id
                || $this->can($user, Permission::TRAINING_MANAGE)
                || $this->isCompanyHR($user));
    }

    public function approveAsFinance(User $user, TrainingBudgetRequest $request): bool
    {
        return $this->inCurrentOrg($request)
            && ($this->can($user, Permission::TRAINING_BUDGET_APPROVE_FINANCE) || $this->isCompanyHR($user));
    }
}
