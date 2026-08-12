<?php

namespace App\Policies\Asset;

use App\Models\Asset\Asset;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Authorization\Permission;

/**
 * Authorization for the asset register.
 *
 * First policy written against the permission catalogue rather than role
 * names. The combined helpers apply the tenancy check, so no method here
 * repeats it — omitting it is how records leak between organizations while
 * every role check still passes.
 */
class AssetPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, Permission::ASSETS_VIEW, Permission::ASSETS_VIEW_OWN);
    }

    /**
     * Broad view, or the narrow one limited to what the user is holding.
     *
     * An employee should be able to see the laptop assigned to them without
     * being able to browse the register.
     */
    public function view(User $user, Asset $asset): bool
    {
        if (! $this->inCurrentOrg($asset)) {
            return false;
        }

        if ($this->can($user, Permission::ASSETS_VIEW)) {
            return true;
        }

        return $this->can($user, Permission::ASSETS_VIEW_OWN)
            && $asset->assigned_to !== null
            && $asset->assigned_to === $user->employeeId();
    }

    public function create(User $user): bool
    {
        return $this->can($user, Permission::ASSETS_CREATE);
    }

    public function update(User $user, Asset $asset): bool
    {
        return $this->canTouch($user, Permission::ASSETS_UPDATE, $asset);
    }

    public function delete(User $user, Asset $asset): bool
    {
        return $this->canTouch($user, Permission::ASSETS_DELETE, $asset);
    }

    /**
     * Assigning custody is separate from editing the record.
     *
     * Someone correcting a serial number should not thereby be able to move
     * equipment between people.
     */
    public function assign(User $user, Asset $asset): bool
    {
        return $this->canTouch($user, Permission::ASSETS_ASSIGN, $asset);
    }

    /**
     * Deciding on a request for equipment.
     *
     * Takes no asset, because a request is for something the organisation may
     * not own yet — that is often the point of it. `assets.approve` has existed
     * in the catalogue since the module was scaffolded and nothing ever
     * consulted it, so every stage of the chain returned "unauthorized"
     * regardless of who was asking.
     */
    public function approve(User $user): bool
    {
        return $this->can($user, Permission::ASSETS_APPROVE);
    }

    /** Anyone holding the asset may raise a fault against it. */
    public function reportIssue(User $user, Asset $asset): bool
    {
        if (! $this->inCurrentOrg($asset)) {
            return false;
        }

        if ($this->can($user, Permission::ASSETS_REPORT_ISSUE)) {
            return true;
        }

        return $asset->assigned_to !== null
            && $asset->assigned_to === $user->employeeId();
    }

    public function resolveIssue(User $user, Asset $asset): bool
    {
        return $this->canTouch($user, Permission::ASSETS_RESOLVE_ISSUE, $asset);
    }
}
