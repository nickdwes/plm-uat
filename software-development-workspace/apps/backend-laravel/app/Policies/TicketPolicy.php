<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Permission;

/**
 * Who may do what on the service desk.
 *
 * Every ability is a named method here. The payroll policy was missing an
 * `update` and nothing supplies a default, so six endpoints denied silently for
 * everybody until it was found — this class exists in full for that reason.
 */
class TicketPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, Permission::SUPPORT_VIEW, Permission::SUPPORT_VIEW_OWN);
    }

    public function view(User $user): bool
    {
        return $this->canAny($user, Permission::SUPPORT_VIEW, Permission::SUPPORT_VIEW_OWN);
    }

    public function create(User $user): bool
    {
        return $this->canAny($user, Permission::SUPPORT_CREATE, Permission::SUPPORT_UPDATE);
    }

    /** Replying, noting and reclassifying. */
    public function update(User $user): bool
    {
        return $this->can($user, Permission::SUPPORT_UPDATE);
    }

    public function assign(User $user): bool
    {
        return $this->can($user, Permission::SUPPORT_ASSIGN);
    }

    /**
     * Closing a ticket.
     *
     * Its own permission because closing is where an SLA outcome is fixed: the
     * resolution clock stops, and a service review is written from that number.
     */
    public function close(User $user): bool
    {
        return $this->can($user, Permission::SUPPORT_CLOSE);
    }

    public function logTime(User $user): bool
    {
        return $this->canAny($user, Permission::SUPPORT_LOG_TIME, Permission::SUPPORT_UPDATE);
    }

    /** Statuses, types and the matrix that decides how the desk is prioritised. */
    public function manageConfig(User $user): bool
    {
        return $this->can($user, Permission::SUPPORT_MANAGE_CONFIG);
    }
}
