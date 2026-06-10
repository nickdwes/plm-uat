<?php

namespace App\Policies\Training;

use App\Models\Training\VendorPartner;
use App\Models\User;
use App\Policies\BasePolicy;

class VendorPartnerPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        // HR managers and department managers can view vendor compliance
        return $this->isCompanyHR($user) || $this->isManager($user);
    }

    public function view(User $user, VendorPartner $vendor): bool
    {
        return $this->isCompanyHR($user) || $this->isManager($user);
    }

    public function create(User $user): bool
    {
        return $this->isCompanyHR($user);
    }

    public function update(User $user, VendorPartner $vendor): bool
    {
        return $this->isCompanyHR($user);
    }

    public function delete(User $user, VendorPartner $vendor): bool
    {
        return $this->isCompanyHR($user);
    }
}
