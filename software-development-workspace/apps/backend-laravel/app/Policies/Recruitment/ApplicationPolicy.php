<?php

namespace App\Policies\Recruitment;

use App\Models\User;
use App\Policies\BasePolicy;
use App\Models\Recruitment\Application;

class ApplicationPolicy extends BasePolicy
{
    public function view(User $user, Application $application): bool
    {
        return $this->isManager($user);
    }

    public function create(User $user): bool
    {
        return $this->isManager($user);
    }

    public function moveStage(User $user, Application $application): bool
    {
        return $this->isManager($user);
    }
}
