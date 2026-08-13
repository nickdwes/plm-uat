<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Organization;
use App\Services\Integration\LicenceSkus;
use App\Services\Integration\MicrosoftInventory;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * What Microsoft says we have, next to what the register says.
 *
 * Dry run only, for now, and on purpose. The interesting half of this job is
 * matching licensed accounts to employees, and that match is where an import
 * does real damage if it is wrong — a mis-matched seat puts somebody else's
 * licence on somebody's record, and an unmatched one quietly invents a person.
 * So the first thing this ever did was show its working and write nothing.
 */
class SyncMicrosoftInventory extends Command
{
    protected $signature = 'assets:sync-microsoft
                            {--org= : Organization slug the estate belongs to}
                            {--dry-run : Report only, write nothing (currently the only mode)}
                            {--azure : Include the Azure resource inventory}';

    protected $description = 'Read Microsoft 365 licences and Azure resources, and compare them with the asset register';

    public function handle(MicrosoftInventory $ms, LicenceSkus $skus): int
    {
        if (! $this->option('dry-run')) {
            $this->components->error('Only --dry-run is implemented. Writing to the register lands next.');

            return self::FAILURE;
        }

        try {
            $organization = $this->resolveOrganization();
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Reading Microsoft 365 for {$organization->name}.");
        $this->line('  Permissions held: ' . (implode(', ', $ms->grantedRoles()) ?: '(none)'));
        $this->newLine();

        $skuRows = $ms->subscribedSkus();
        $this->reportLicences($skuRows);
        $this->reportPressure($skuRows);
        $this->reportOrphans($ms, $organization);

        if ($this->option('azure')) {
            $this->reportAzure($ms);
        }

        $this->newLine();
        $this->components->warn('Dry run — nothing was written.');

        return self::SUCCESS;
    }

    // ── Licences ──────────────────────────────────────────────────────────────

    private function reportLicences(array $skuRows): void
    {
        $this->components->twoColumnDetail('<fg=cyan>SUBSCRIBED SKUS</>', '');

        $this->table(
            ['Licence', 'Bucket', 'Used', 'Seats', 'Spare', 'Status'],
            collect($skuRows)->map(fn (array $s): array => [
                $s['name'],
                $s['classification'] === LicenceSkus::PAID
                    ? '<fg=green>paid</>'
                    : "<fg=gray>{$s['classification']}</>",
                $s['consumed_reported'],
                $s['seats_total'],
                $s['classification'] === LicenceSkus::PAID ? $s['seats_spare'] : '—',
                $s['capability_status'] === 'Enabled' ? '' : "<fg=yellow>{$s['capability_status']}</>",
            ])->all(),
        );

        $paid = collect($skuRows)->where('classification', LicenceSkus::PAID);

        $bought = (int) $paid->sum('seats_total');
        $used   = (int) $paid->sum('consumed_reported');

        /*
         * Counted across paid SKUs only.
         *
         * Including the free ones puts three million nominal seats into the
         * denominator and renders utilisation as 0% on a healthy estate, which
         * is what the naive version of this did.
         */
        $this->components->twoColumnDetail(
            'Paid SKUs',
            sprintf(
                '%d of %d — %d seats used of %d (%d%% utilisation)',
                $paid->count(),
                count($skuRows),
                $used,
                $bought,
                $bought > 0 ? (int) round(100 * $used / $bought) : 0,
            ),
        );

        $excluded = collect($skuRows)->where('classification', '!=', LicenceSkus::PAID);

        if ($excluded->isNotEmpty()) {
            $this->components->twoColumnDetail(
                'Set aside',
                $excluded->count() . ' free or trial SKUs carrying '
                . number_format((int) $excluded->sum('seats_total')) . ' nominal seats',
            );
        }
    }

    /**
     * The two ends that cost money — full, and barely touched.
     *
     * Being at the ceiling is the more urgent of the two: an idle seat is spend
     * you can recover at the next renewal, but a full SKU is somebody's first
     * morning going wrong, and it goes wrong on the day they arrive.
     */
    private function reportPressure(array $skuRows): void
    {
        $paid = collect($skuRows)->where('classification', LicenceSkus::PAID);

        $full = $paid->filter(fn (array $s) => $s['seats_total'] > 0 && $s['seats_spare'] === 0);

        if ($full->isNotEmpty()) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=red>NO SEATS LEFT</>', '');
            foreach ($full as $s) {
                $this->components->twoColumnDetail(
                    '  ' . $s['name'],
                    "{$s['consumed_reported']} / {$s['seats_total']}",
                );
            }
        }

        // A third consumed is the point at which the spare seats are worth a
        // conversation rather than a note.
        $idle = $paid
            ->filter(fn (array $s) => $s['seats_total'] >= 5
                && $s['consumed_reported'] / max(1, $s['seats_total']) < 0.34)
            ->sortByDesc('seats_spare');

        if ($idle->isNotEmpty()) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=yellow>MOSTLY IDLE</>', '');
            foreach ($idle as $s) {
                $this->components->twoColumnDetail(
                    '  ' . $s['name'],
                    "{$s['seats_spare']} idle of {$s['seats_total']}",
                );
            }
        }
    }

    // ── The match ─────────────────────────────────────────────────────────────

    /**
     * Licensed accounts nobody on the payroll answers for.
     *
     * This is the reason the integration is worth building. A licensed account
     * with no active employee behind it is either a leaver still consuming a
     * paid seat — and usually still holding mailbox access, which makes it a
     * security finding as much as a cost one — or a shared mailbox nobody
     * wrote down. Both are worth knowing; they are not the same thing, and the
     * report does not pretend to tell them apart.
     */
    private function reportOrphans(MicrosoftInventory $ms, Organization $organization): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>LICENSED ACCOUNTS</>', '');

        $licensed = $ms->licensedUsers();

        /*
         * Matched on address rather than name. Two people called J. Mwangi is
         * an ordinary Tuesday; two people with one mailbox is not.
         */
        $employees = Employee::forOrganization($organization->id)
            ->get(['id', 'first_name', 'last_name', 'work_email', 'personal_email', 'status']);

        $byEmail = [];
        foreach ($employees as $employee) {
            foreach ([$employee->work_email, $employee->personal_email] as $address) {
                if (filled($address)) {
                    $byEmail[mb_strtolower(trim($address))] = $employee;
                }
            }
        }

        $matched = 0;
        $orphans = [];

        foreach ($licensed as $account) {
            $candidates = array_filter([
                $account['mail'] ? mb_strtolower(trim($account['mail'])) : null,
                $account['upn'] ? mb_strtolower(trim($account['upn'])) : null,
            ]);

            $hit = null;
            foreach ($candidates as $address) {
                if (isset($byEmail[$address])) {
                    $hit = $byEmail[$address];
                    break;
                }
            }

            if ($hit) {
                $matched++;

                continue;
            }

            $orphans[] = [
                $account['name'] ?? '—',
                $account['upn'] ?? $account['mail'] ?? '—',
                count($account['sku_ids']),
                $account['enabled'] ? '' : '<fg=red>sign-in blocked</>',
            ];
        }

        $this->components->twoColumnDetail('Licensed accounts in Entra', (string) count($licensed));
        $this->components->twoColumnDetail('Matched to an employee', (string) $matched);
        $this->components->twoColumnDetail(
            'Unmatched',
            count($orphans) > 0 ? '<fg=yellow>' . count($orphans) . '</>' : '0',
        );

        /*
         * Reported separately, and first, because this one needs no matching to
         * be true.
         *
         * An account whose sign-in is blocked cannot be in use by anybody, so
         * every licence on it is being paid for and consumed by nobody. Unlike
         * the unmatched list below — which mixes leavers with shared mailboxes
         * and service accounts — there is nothing to interpret here.
         */
        $blocked = collect($licensed)->reject(fn (array $a) => $a['enabled']);

        if ($blocked->isNotEmpty()) {
            $this->newLine();
            $this->components->twoColumnDetail(
                '<fg=red>BLOCKED BUT STILL LICENSED</>',
                $blocked->count() . ' accounts holding '
                . $blocked->sum(fn (array $a) => count($a['sku_ids'])) . ' licences',
            );

            foreach ($blocked->sortBy('name') as $account) {
                $this->components->twoColumnDetail(
                    '  ' . ($account['name'] ?? '—'),
                    ($account['upn'] ?? '—') . '  ×' . count($account['sku_ids']),
                );
            }
        }

        if ($orphans !== []) {
            $this->newLine();
            $this->table(['Account', 'Address', 'Licences', 'Note'], $orphans);
            $this->line(
                '  <fg=gray>Each of these is a leaver still holding paid seats, a shared mailbox, '
                . 'or a service account. Worth reading before anything writes.</>',
            );

            /*
             * When almost nothing matches, the register is the problem, not the
             * directory — and saying so beats printing a hundred rows that all
             * look like findings.
             */
            if ($matched === 0 && $employees->count() < count($licensed) / 4) {
                $this->newLine();
                $this->components->warn(sprintf(
                    'The employee register holds %d record(s) for %s against %d licensed accounts. '
                    . 'Nothing here is a leaver until the register is populated — this list is the '
                    . 'directory, not a finding.',
                    $employees->count(),
                    $organization->name,
                    count($licensed),
                ));
            }
        }
    }

    // ── Azure ─────────────────────────────────────────────────────────────────

    private function reportAzure(MicrosoftInventory $ms): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>AZURE RESOURCES</>', '');

        $resources = $ms->azureResources();

        $byType = collect($resources)
            ->groupBy('type')
            ->map->count()
            ->sortDesc();

        foreach ($byType as $type => $count) {
            $this->components->twoColumnDetail('  ' . $type, (string) $count);
        }

        $this->components->twoColumnDetail(
            'Total',
            count($resources) . ' resources across '
            . collect($resources)->pluck('resource_group')->unique()->count() . ' resource groups',
        );

        // Tags are where owner and environment already live in Azure, and they
        // are the difference between an inventory and a list of names.
        $untagged = collect($resources)->filter(fn (array $r) => $r['tags'] === [])->count();

        if ($untagged > 0) {
            $this->components->twoColumnDetail(
                '<fg=yellow>Untagged</>',
                "{$untagged} resources carry no tags — no owner, environment or cost centre to import",
            );
        }
    }

    // ── Plumbing ──────────────────────────────────────────────────────────────

    private function resolveOrganization(): Organization
    {
        if ($slug = $this->option('org')) {
            $organization = Organization::where('slug', $slug)->first();

            if (! $organization) {
                throw new RuntimeException("No organization with slug '{$slug}'.");
            }

            return $organization;
        }

        $all = Organization::orderBy('id')->get();

        if ($all->count() === 1) {
            return $all->first();
        }

        throw new RuntimeException(
            'Several organizations exist — say which estate this is with --org=<slug>. Available: '
            . $all->pluck('slug')->implode(', '),
        );
    }
}
