<?php

namespace App\Policies\Training;

use App\Models\Training\CompetencyItem;
use App\Models\Training\RoleCompetencyProfile;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Authorization\Permission;

/**
 * The competencies a role is expected to have.
 *
 * Configuration rather than personal data: what "Senior Engineer" is supposed
 * to know. Readable widely, because people should be able to see what a role
 * they might want asks of them — and changeable only by whoever owns training,
 * because it is the yardstick appraisals and promotions are measured against.
 */
class CompetencyPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, Permission::TRAINING_VIEW, Permission::TRAINING_VIEW_OWN)
            || $this->isEmployee($user);
    }

    public function view(User $user, CompetencyItem|RoleCompetencyProfile $model): bool
    {
        return $this->inCurrentOrg($model) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->can($user, Permission::TRAINING_MANAGE) || $this->isCompanyHR($user);
    }

    public function update(User $user, CompetencyItem|RoleCompetencyProfile $model): bool
    {
        return $this->inCurrentOrg($model)
            && ($this->can($user, Permission::TRAINING_MANAGE) || $this->isCompanyHR($user));
    }

    public function delete(User $user, CompetencyItem|RoleCompetencyProfile $model): bool
    {
        return $this->update($user, $model);
    }
}
