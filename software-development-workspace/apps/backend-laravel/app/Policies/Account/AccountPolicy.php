<?php

namespace App\Policies\Account;

use App\Models\Account\Account;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Authorization\Permission;

/**
 * Authorization for the customer master.
 *
 * The distinction that matters here is between seeing the customer base and
 * seeing your own book. An account manager needs their customers; they do not
 * automatically need everyone else's, and in a group where organisations
 * compete for the same market that is a commercial boundary as much as a
 * privacy one.
 */
class AccountPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_VIEW_OWN);
    }

    public function view(User $user, Account $account): bool
    {
        if (! $this->inCurrentOrg($account)) {
            return false;
        }

        if ($this->can($user, Permission::ACCOUNTS_VIEW)) {
            return true;
        }

        return $this->can($user, Permission::ACCOUNTS_VIEW_OWN)
            && $account->account_manager_id !== null
            && $account->account_manager_id === $user->employeeId();
    }

    public function create(User $user): bool
    {
        return $this->can($user, Permission::ACCOUNTS_CREATE);
    }

    /**
     * Editing a customer's details.
     *
     * Someone holding only `view_own` cannot edit: reading your own book and
     * changing it are different authorities, and conflating them is how a
     * commission dispute becomes a data dispute.
     */
    public function update(User $user, Account $account): bool
    {
        return $this->canTouch($user, Permission::ACCOUNTS_UPDATE, $account);
    }

    public function delete(User $user, Account $account): bool
    {
        return $this->canTouch($user, Permission::ACCOUNTS_DELETE, $account);
    }

    /**
     * Reassigning the account manager.
     *
     * Separate from `update` because it moves a customer between people, which
     * has consequences — visibility, targets, commission — that editing a
     * phone number does not.
     */
    public function assign(User $user, Account $account): bool
    {
        return $this->canTouch($user, Permission::ACCOUNTS_ASSIGN, $account);
    }

    /**
     * Types, sectors and sub-sectors.
     *
     * Reference data is the axis every customer report groups by, so changing
     * it reshapes reporting for everyone rather than for one record.
     */
    public function manageReferenceData(User $user): bool
    {
        return $this->can($user, Permission::ACCOUNTS_MANAGE_REF);
    }
}
