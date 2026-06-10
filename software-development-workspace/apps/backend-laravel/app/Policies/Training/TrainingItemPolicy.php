<?php

namespace App\Policies\Training;

use App\Models\Training\TrainingItem;
use App\Models\User;
use App\Policies\BasePolicy;

class TrainingItemPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        // All authenticated org members can browse the catalog
        return $this->isEmployee($user);
    }

    public function view(User $user, TrainingItem $item): bool
    {
        return $this->isEmployee($user);
    }

    public function create(User $user): bool
    {
        return $this->isCompanyHR($user);
    }

    public function update(User $user, TrainingItem $item): bool
    {
        return $this->isCompanyHR($user);
    }

    public function delete(User $user, TrainingItem $item): bool
    {
        return $this->isCompanyHR($user);
    }
}
