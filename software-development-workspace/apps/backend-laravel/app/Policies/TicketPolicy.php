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

    /**
     * Triage: validating the customer and contract, categorising, placing.
     *
     * The service desk analyst's job. Kept separate from answering a ticket
     * because it is a different competence — knowing the contract structure
     * rather than knowing the technology.
     */
    public function triage(User $user): bool
    {
        return $this->canAny($user, Permission::SUPPORT_TRIAGE, Permission::SUPPORT_ASSIGN);
    }

    /**
     * Moving a ticket that somebody already holds.
     *
     * Its own permission so ordinary agents cannot take work off each other.
     * Every reassignment records who moved it and why.
     */
    public function reassign(User $user): bool
    {
        return $this->canAny($user, Permission::SUPPORT_REASSIGN, Permission::SUPPORT_TRIAGE);
    }

    public function escalate(User $user): bool
    {
        return $this->canAny($user, Permission::SUPPORT_ESCALATE, Permission::SUPPORT_TRIAGE);
    }

    /** Contract support structures, engineers and their competencies. */
    public function manageContracts(User $user): bool
    {
        return $this->canAny($user, Permission::SUPPORT_MANAGE_CONTRACTS, Permission::SUPPORT_MANAGE_CONFIG);
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
