<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use App\Services\Identity\DirectoryDiscovery;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Ask Office 365 about everybody, and record what it says.
 *
 * One person at a time from the screen is right for a new joiner or a problem;
 * it is not right for a first run across a whole book, or for the nightly
 * catch-up that keeps a leaver from still being marked SSO a month after their
 * mailbox was disabled.
 *
 * Dry by default. This changes how people sign in, and a command that does
 * that on a typo is a command nobody should run.
 */
class ReconcileDirectory extends Command
{
    protected $signature = 'identity:reconcile
        {--org= : Organisation slug or id; every organisation with a directory connection by default}
        {--only-unresolved : Skip anybody already classified}
        {--apply : Actually write. Without it, nothing changes}';

    protected $description = 'Reconcile every login against Office 365 and set its authentication method';

    public function handle(): int
    {
        $organizations = Organization::query()
            ->when($this->option('org'), fn ($q, $org) => is_numeric($org)
                ? $q->where('id', (int) $org)
                : $q->where('slug', $org))
            ->whereNotNull('identity_connection_id')
            ->orderBy('id')
            ->get();

        if ($organizations->isEmpty()) {
            $this->error('No organisation has a directory connection named. Set one under Connected Tenants.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        foreach ($organizations as $org) {
            $this->line("── {$org->slug} ──");

            try {
                $discovery = DirectoryDiscovery::forOrganization($org->id);
            } catch (RuntimeException $e) {
                $this->error('  ' . $e->getMessage());

                continue;
            }

            $this->line("  via {$discovery->connectionName()}");

            $users = User::query()
                ->when($this->option('only-unresolved'),
                    fn ($q) => $q->where('auth_method', User::AUTH_UNRESOLVED))
                ->orderBy('email')
                ->get();

            $tally = [];

            foreach ($users as $user) {
                if ($apply) {
                    $fresh = $discovery->reconcile($user);
                    $status = $fresh->discovery_status;
                } else {
                    $status = $discovery->look($user)['status'];
                }

                $tally[$status] = ($tally[$status] ?? 0) + 1;

                /*
                 * A refused lookup means the permission is missing, and every
                 * remaining call will be refused the same way. Stopping saves
                 * a few hundred pointless requests and, more importantly,
                 * reports the cause rather than burying it in a tally.
                 */
                if ($status === User::DISCOVERY_ERROR) {
                    $this->error('  Stopped: Microsoft refused a lookup. Check the app registration holds '
                        . "'User.Read.All' with admin consent.");

                    break;
                }
            }

            foreach ($tally as $status => $count) {
                $this->line(sprintf('  %-16s %d', $status, $count));
            }
        }

        if (! $apply) {
            $this->warn('Dry run — nothing was written. Add --apply to record it.');
        }

        return self::SUCCESS;
    }
}
