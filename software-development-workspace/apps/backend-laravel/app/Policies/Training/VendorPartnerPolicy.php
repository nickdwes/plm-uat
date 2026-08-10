<?php

namespace App\Policies\Training;

use App\Models\Training\VendorPartner;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Authorization\Permission;

/**
 * OEMs and training providers.
 *
 * The only domain that had a policy and no permissions at all behind it, so
 * there was nothing to grant and access fell back to being HR or a manager —
 * which is both too wide and unable to describe the people who actually deal
 * with vendors. `vendors.view` and `vendors.manage` now exist.
 *
 * Worth guarding: a vendor record carries the commercial relationship, the
 * certifications we are required to hold, and — through opportunities — what we
 * pay for their professional services.
 */
class VendorPartnerPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, Permission::VENDORS_VIEW, Permission::VENDORS_MANAGE)
            || $this->isCompanyHR($user);
    }

    public function view(User $user, VendorPartner $vendor): bool
    {
        return $this->inCurrentOrg($vendor) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->can($user, Permission::VENDORS_MANAGE) || $this->isCompanyHR($user);
    }

    public function update(User $user, VendorPartner $vendor): bool
    {
        return $this->inCurrentOrg($vendor)
            && ($this->can($user, Permission::VENDORS_MANAGE) || $this->isCompanyHR($user));
    }

    public function delete(User $user, VendorPartner $vendor): bool
    {
        return $this->update($user, $vendor);
    }
}
