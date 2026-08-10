<?php

namespace App\Policies\Training;

use App\Models\Training\TrainingItem;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Authorization\Permission;

/**
 * The training catalogue.
 *
 * Reading it is broad on purpose — people should be able to see what they could
 * learn. Changing it is not: a course carries a cost and a provider, and the
 * catalogue is what training budgets are approved against.
 */
class TrainingItemPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, Permission::TRAINING_VIEW, Permission::TRAINING_VIEW_OWN)
            || $this->isEmployee($user);
    }

    public function view(User $user, TrainingItem $item): bool
    {
        return $this->inCurrentOrg($item) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->can($user, Permission::TRAINING_MANAGE) || $this->isCompanyHR($user);
    }

    public function update(User $user, TrainingItem $item): bool
    {
        return $this->inCurrentOrg($item)
            && ($this->can($user, Permission::TRAINING_MANAGE) || $this->isCompanyHR($user));
    }

    public function delete(User $user, TrainingItem $item): bool
    {
        return $this->update($user, $item);
    }
}
