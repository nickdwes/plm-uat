<?php

namespace App\Policies\Integration;

use App\Models\Integration\IntegrationConnection;
use App\Models\User;
use App\Policies\BasePolicy;

/**
 * Who may hold another system's credentials.
 *
 * Separate from the asset permissions on purpose. Somebody who maintains the
 * register — adding laptops, closing disposals — is not thereby somebody who
 * should be handed a credential that can read an entire directory. The two
 * jobs overlap in practice and should not overlap in what they authorise.
 *
 * `sync` is its own permission again, because running an import is an action
 * against production data that somebody might be trusted to do without being
 * trusted to change where the data comes from.
 */
class IntegrationConnectionPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, 'integrations.view', 'integrations.manage');
    }

    public function view(User $user, IntegrationConnection $connection): bool
    {
        return $this->canTouch($user, 'integrations.view', $connection)
            || $this->canTouch($user, 'integrations.manage', $connection);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'integrations.manage');
    }

    public function update(User $user, IntegrationConnection $connection): bool
    {
        return $this->canTouch($user, 'integrations.manage', $connection);
    }

    public function delete(User $user, IntegrationConnection $connection): bool
    {
        return $this->canTouch($user, 'integrations.manage', $connection);
    }

    /** Running an import, as opposed to configuring where it reads from. */
    public function sync(User $user, IntegrationConnection $connection): bool
    {
        return $this->canTouch($user, 'integrations.sync', $connection)
            || $this->canTouch($user, 'integrations.manage', $connection);
    }
}
