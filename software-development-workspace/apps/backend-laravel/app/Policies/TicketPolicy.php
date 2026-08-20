<?php

namespace App\Policies;

use App\Models\Support\Ticket;
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

    /**
     * Seeing one ticket.
     *
     * `support.view_own` used to be treated as though it were
     * `support.view` — the check passed on either and nothing narrowed
     * afterwards, so a permission whose whole purpose is to limit somebody to
     * their own tickets granted them all of them.
     *
     * A ticket is "own" if the person raised it or holds it. The service desk
     * analyst following it is deliberately not included: that is what
     * `support.view` is for, and an analyst without it should not acquire
     * visibility by being named on a ticket.
     */
    public function view(User $user, ?Ticket $ticket = null): bool
    {
        if ($this->can($user, Permission::SUPPORT_VIEW)) {
            return true;
        }

        if (! $this->can($user, Permission::SUPPORT_VIEW_OWN)) {
            return false;
        }

        // No record to judge means the listing, which narrows itself.
        return $ticket === null || $this->owns($user, $ticket);
    }

    /** Raised it, holds it, or is the engineer it was escalated to. */
    public function owns(User $user, Ticket $ticket): bool
    {
        return in_array($user->id, array_filter([
            $ticket->requester_user_id,
            $ticket->assigned_to,
            $ticket->escalation_owner_id,
        ]), true);
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

    /**
     * Signing off a change or a service request.
     *
     * The opposite job from raising one. Whoever asks for a change should not
     * be able to wave it through, and folding this into support.update would
     * make every approval a formality.
     */
    public function approveChange(User $user): bool
    {
        return $this->can($user, Permission::SUPPORT_APPROVE);
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

    /**
     * Reading the service review figures.
     *
     * Deliberately not `manageConfig`. The person who writes the monthly review
     * needs the numbers, not the ability to change what the desk is judged
     * against — and letting the second imply the first would mean nobody could
     * be given the report without also being handed the priority matrix.
     */
    public function report(User $user): bool
    {
        return $this->canAny($user, Permission::SUPPORT_REPORT, Permission::SUPPORT_MANAGE_CONFIG);
    }

    /** Statuses, types and the matrix that decides how the desk is prioritised. */
    public function manageConfig(User $user): bool
    {
        return $this->can($user, Permission::SUPPORT_MANAGE_CONFIG);
    }
}
