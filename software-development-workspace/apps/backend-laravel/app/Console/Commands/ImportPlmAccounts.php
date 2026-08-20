<?php

namespace App\Console\Commands;

use App\Models\Account\Account;
use App\Models\Account\AccountContact;
use App\Models\Account\AccountContract;
use App\Models\Organization;
use App\Models\PlmImportMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bring the customer master data across from the old PLM.
 *
 * Accounts, their contacts and their contracts — the records everything else
 * hangs off. Tickets, engineers and the portal all resolve to these, so they
 * come first and alone: a bad account mapping discovered after eight thousand
 * tickets have been attached to it is a different problem from one discovered
 * against seventy rows somebody can read.
 *
 * ── It rehearses by default ──
 *
 * Nothing is written without `--apply`. An import is the least reversible thing
 * anybody runs, and "what would this do" has to be answerable before it does
 * it, not after.
 *
 * ── It never guesses quietly ──
 *
 * A sector that does not match, a country nobody has heard of, a contract with
 * no dates: each is left unset and *named* in the summary. The alternative —
 * picking the closest thing — produces a database that looks complete and is
 * wrong in ways nobody can find, which is the failure this whole rebuild keeps
 * running into in the system being replaced.
 *
 * ── It can be run twice ──
 *
 * Every legacy row is recorded in plm_import_map, so a second run updates what
 * it already brought across rather than duplicating it. Imports always get run
 * more than once.
 */
class ImportPlmAccounts extends Command
{
    protected $signature = 'plm:import-accounts
        {--org=konvergenz : Organisation slug the data belongs to}
        {--apply : Actually write. Without it, nothing changes}';

    protected $description = 'Import accounts, contacts and contracts from the old PLM';

    /** East African countries the old system knew, as ISO codes. */
    private const COUNTRIES = [
        'kenya' => 'KE', 'uganda' => 'UG', 'tanzania' => 'TZ',
        'rwanda' => 'RW', 'burundi' => 'BI', 'ethiopia' => 'ET',
        'south sudan' => 'SS', 'somalia' => 'SO', 'congo' => 'CD',
    ];

    /**
     * The fields a merge considers.
     *
     * Only what the import actually reads. Two records for one customer differ
     * by slug, uid, author and creation date as a matter of course, and
     * reporting those buries the two that need a human — which sector the
     * customer is in, and what kind of account they are.
     */
    private const MERGE_FIELDS = [
        'status', 'account_type', 'sector', 'country', 'physical_location',
        'number_of_branches', 'number_of_employees', 'kra_pin', 'vat',
        'fund_source', 'details', 'comments',
    ];

    /** Fields where the old system wrote zero because it had no null. */
    private const COUNT_FIELDS = ['number_of_branches', 'number_of_employees'];

    private bool $apply = false;
    private int $orgId;

    /** Things that could not be mapped, reported rather than silently dropped. */
    private array $unmapped = [];

    public function handle(): int
    {
        $this->apply = (bool) $this->option('apply');

        $org = Organization::where('slug', $this->option('org'))->first();

        if (! $org) {
            $this->error("No organisation with slug '{$this->option('org')}'.");

            return self::FAILURE;
        }

        $this->orgId = $org->id;
        app()->instance('current.organization_id', $org->id);

        /*
         * Reached rather than inspected. Checking that PLM_DB_HOST is set
         * proves an environment variable exists, not that there is a database
         * behind it — and the failure that matters is the one where the
         * connection details are present and wrong.
         */
        try {
            $this->legacy()->getPdo();
        } catch (\Throwable $e) {
            $this->error('Cannot reach the old PLM database: ' . $e->getMessage());
            $this->line('Set PLM_DB_HOST, PLM_DB_DATABASE, PLM_DB_USERNAME and PLM_DB_PASSWORD.');

            return self::FAILURE;
        }

        $this->line($this->apply
            ? "Importing into <options=bold>{$org->name}</>."
            : "Rehearsing against <options=bold>{$org->name}</>. Nothing will be written — pass --apply to commit.");
        $this->newLine();

        $run = function () {
            $accounts  = $this->importAccounts();
            $contacts  = $this->importContacts();
            $contracts = $this->importContracts();

            $this->summarise('Accounts', $accounts);
            $this->summarise('Contacts', $contacts);
            $this->summarise('Contracts', $contracts);
        };

        /*
         * A rehearsal runs the real code inside a transaction it rolls back,
         * rather than a separate "what if" path. Two code paths means the one
         * that gets tested is not the one that runs.
         */
        if ($this->apply) {
            DB::transaction($run);
        } else {
            DB::beginTransaction();

            try {
                $run();
            } finally {
                DB::rollBack();
            }
        }

        $this->reportUnmapped();

        return self::SUCCESS;
    }

    // ── Accounts ──────────────────────────────────────────────────────────────

    private function importAccounts(): array
    {
        $rows = $this->legacy()->table('mdl_accounts')->orderBy('id')->get();

        $made = 0;
        $updated = 0;
        $merged = 0;

        /*
         * The old system let the same customer be entered twice — no unique
         * name, so two people created "Central Bank of Kenya" nine months
         * apart. Both halves carry real work: one of the duplicated pairs has
         * a contract filed under each id, so keeping only one would lose a
         * contract and every ticket that resolves through it.
         *
         * So duplicates are merged rather than skipped, and *both* legacy ids
         * are mapped to the one account — which is what lets the contacts and
         * contracts underneath them find their way to the same customer.
         */
        foreach ($rows->groupBy(fn ($r) => mb_strtolower(trim((string) $r->name))) as $group) {
            $row = $this->fold($group);

            if ($group->count() > 1) {
                $merged += $group->count() - 1;
            }

            $attributes = [
                'organization_id'     => $this->orgId,
                'name'                => trim((string) $row->name),
                'status'              => $this->accountStatus($row->status),
                'account_type_id'     => $this->lookup('account_types', $this->legacyName('mdl_accounts_types', $row->account_type), 'account type'),
                'account_sector_id'   => $this->lookup('account_sectors', $this->legacyName('mdl_accounts_sectors', $row->sector), 'sector'),
                'country'             => $this->country($this->legacyName('mdl_accounts_countries', $row->country)),
                'physical_location'   => $this->text($row->physical_location),
                'number_of_branches'  => $this->number($row->number_of_branches),
                'number_of_employees' => $this->number($row->number_of_employees),
                'tax_pin'             => $this->text($row->kra_pin),
                'fund_source'         => $this->text($row->fund_source),
                'details'             => $this->text($row->details),
                'enrolled_on'         => $row->created_at ? substr((string) $row->created_at, 0, 10) : null,
                'created_at'          => $row->created_at ?: now(),
            ];

            [$record, $isNew] = $this->upsert('mdl_accounts', $row->id, Account::class, $attributes, [
                'name' => $row->name, 'status' => $row->status,
            ]);

            $isNew ? $made++ : $updated++;

            // Every id in the group points at the one account, so nothing
            // filed under the duplicate is orphaned.
            foreach ($group as $duplicate) {
                if ($duplicate->id !== $row->id) {
                    $this->mapAlso('mdl_accounts', $duplicate->id, Account::class, $record->id,
                        "merged into legacy account {$row->id}");
                }
            }
        }

        return [
            'read' => $rows->count(), 'created' => $made,
            'updated' => $updated, 'merged away' => $merged,
        ];
    }

    /**
     * One row from several that name the same customer.
     *
     * The oldest is the base — it is the record the account was originally
     * opened as. Later entries fill only what the original left blank, so a
     * second attempt at the same customer contributes its address and phone
     * number without overwriting anything somebody deliberately set.
     *
     * Where both hold a *different* non-empty value there is no right answer
     * to compute, so the disagreement is reported and the original kept. A
     * merge that silently picks one of two sectors is a merge nobody can audit.
     */
    private function fold(\Illuminate\Support\Collection $group): object
    {
        $base = clone $group->sortBy('id')->first();

        if ($group->count() === 1) {
            return $base;
        }

        $ids = $group->pluck('id')->implode(' + ');

        foreach ($group->sortBy('id')->slice(1) as $later) {
            foreach (self::MERGE_FIELDS as $field) {
                $incoming = $this->present($field, $later->{$field} ?? null);

                if ($incoming === null) {
                    continue;
                }

                if ($this->present($field, $base->{$field} ?? null) === null) {
                    $base->{$field} = $later->{$field};

                    continue;
                }

                if ((string) $base->{$field} !== (string) $later->{$field}) {
                    $this->note('account', (int) $base->id,
                        "({$base->name}) merged from ids {$ids}: {$field} differs — kept "
                        . '"' . $this->readable($field, $base->{$field}) . '", discarded "'
                        . $this->readable($field, $later->{$field}) . '"');
                }
            }

            // The customer has been ours since the first time somebody opened
            // them, not since the duplicate was created.
            if ($later->created_at && $later->created_at < $base->created_at) {
                $base->created_at = $later->created_at;
            }
        }

        return $base;
    }

    private function accountStatus(?string $status): string
    {
        return match (mb_strtolower(trim((string) $status))) {
            'active'   => 'active',
            'inactive' => 'inactive',
            // Blank in the old system meant nobody had decided, which is what
            // pending means here — not a quiet promotion to active.
            default    => 'pending',
        };
    }

    // ── Contacts ──────────────────────────────────────────────────────────────

    private function importContacts(): array
    {
        $rows = $this->legacy()->table('mdl_accounts_contacts')->orderBy('id')->get();

        $made = 0;
        $updated = 0;
        $orphaned = 0;

        foreach ($rows as $row) {
            $account = PlmImportMap::resolve($this->orgId, 'mdl_accounts', $row->account);

            if (! $account) {
                $this->note('contact', $row->id, "belongs to account {$row->account}, which did not come across");
                $orphaned++;

                continue;
            }

            $attributes = [
                'organization_id' => $this->orgId,
                'account_id'      => $account->target_id,
                'title'           => $this->text($row->title),
                'first_name'      => trim((string) $row->first_name),
                'last_name'       => trim((string) $row->last_name),
                'designation'     => $this->text($row->designation),
                'department'      => $this->legacyName('mdl_accounts_departments', $row->department),
                'email'           => $this->text($row->email),
                'phone'           => $this->text($row->phone),
                'alternate_phone' => $this->text($row->alternate_phone),
                'is_active'       => true,
                'created_at'      => $row->created_at ?: now(),
            ];

            [, $isNew] = $this->upsert('mdl_accounts_contacts', $row->id, AccountContact::class, $attributes, [
                'email' => $row->email, 'account' => $row->account,
            ]);

            $isNew ? $made++ : $updated++;
        }

        $primaries = $this->markSolePrimaries();

        return [
            'read' => $rows->count(), 'created' => $made, 'updated' => $updated,
            'orphaned' => $orphaned, 'primary set' => $primaries,
        ];
    }

    /**
     * Where an account has exactly one contact, that contact is the primary.
     *
     * Not a guess — with one contact there is nobody else it could be. Accounts
     * with several are left alone and counted, because picking the lowest id as
     * "primary" would put a name on correspondence that nobody chose.
     */
    private function markSolePrimaries(): int
    {
        $sole = AccountContact::where('organization_id', $this->orgId)
            ->select('account_id')
            ->groupBy('account_id')
            ->havingRaw('count(*) = 1')
            ->pluck('account_id');

        return AccountContact::where('organization_id', $this->orgId)
            ->whereIn('account_id', $sole)
            ->where('is_primary', false)
            ->update(['is_primary' => true]);
    }

    // ── Contracts ─────────────────────────────────────────────────────────────

    private function importContracts(): array
    {
        $rows = $this->legacy()->table('mdl_contract_management')->orderBy('id')->get();

        $made = 0;
        $updated = 0;
        $orphaned = 0;
        $held = 0;

        foreach ($rows as $row) {
            $account = PlmImportMap::resolve($this->orgId, 'mdl_accounts', $row->account);

            if (! $account) {
                $this->note('contract', $row->id, "belongs to account {$row->account}, which did not come across");
                $orphaned++;

                continue;
            }

            /*
             * A contract runs between two dates. Both columns are required
             * here, and inventing either would be inventing a contract term —
             * the one thing an import of somebody's commercial agreements must
             * not do. All fifty-five in the live dump have both; this exists
             * so that if a fifty-sixth does not, it is named rather than
             * arriving with a date nobody agreed to.
             */
            $missing = collect(['start date' => $row->start_date, 'end date' => $row->end_date])
                ->filter(fn ($date) => ! $this->date($date))->keys();

            if ($missing->isNotEmpty()) {
                $this->note('contract', $row->id,
                    "(\"{$row->name}\") has no {$missing->implode(' and no ')} and was left out");
                $held++;

                continue;
            }

            $attributes = [
                'organization_id'   => $this->orgId,
                'account_id'        => $account->target_id,
                'name'              => trim((string) $row->name) ?: 'Untitled contract',
                'contract_number'   => $this->text($row->number),
                'contract_value'    => is_numeric($row->value) ? $row->value : null,
                'start_date'        => $this->date($row->start_date),
                'end_date'          => $this->date($row->end_date),
                'status'            => $this->contractStatus($row),
                'project_reference' => $this->text($row->project),
                'created_at'        => $row->created_at ?: now(),
            ];

            [, $isNew] = $this->upsert('mdl_contract_management', $row->id, AccountContract::class, $attributes, [
                'name' => $row->name, 'number' => $row->number, 'account' => $row->account,
            ]);

            $isNew ? $made++ : $updated++;
        }

        return [
            'read' => $rows->count(), 'created' => $made, 'updated' => $updated,
            'orphaned' => $orphaned, 'incomplete dates' => $held,
        ];
    }

    /**
     * The old table had no status column, so it is read off the end date.
     *
     * Derived rather than defaulted to active: a contract that ended in 2024
     * is not a support entitlement, and importing fifty-five of them as live
     * would hand every one of those customers cover they are not paying for —
     * and the SLA clock would honour it.
     */
    private function contractStatus(object $row): string
    {
        return $this->date($row->end_date) < now()->toDateString() ? 'expired' : 'active';
    }

    // ── Shared ────────────────────────────────────────────────────────────────

    /**
     * Create or update, and record what became what.
     *
     * @return array{0: object, 1: bool}  the record, and whether it is new
     */
    private function upsert(string $table, int $sourceId, string $model, array $attributes, array $snapshot): array
    {
        $existing = PlmImportMap::resolve($this->orgId, $table, $sourceId);

        if ($existing && ($record = $model::find($existing->target_id))) {
            $record->forceFill($attributes)->save();

            $existing->forceFill(['source_snapshot' => $snapshot])->save();

            return [$record, false];
        }

        $record = new $model();
        $record->forceFill($attributes)->save();

        PlmImportMap::create([
            'organization_id' => $this->orgId,
            'source_table'    => $table,
            'source_id'       => $sourceId,
            'target_type'     => $model,
            'target_id'       => $record->id,
            'source_snapshot' => $snapshot,
        ]);

        return [$record, true];
    }

    /**
     * A value as a person would read it.
     *
     * The conflicts worth a human's time are the ones about what kind of
     * customer this is — and "account_type differs, kept 1, discarded 3" asks
     * that human to go and look up what 1 and 3 mean. A report somebody has to
     * decode is a report nobody reads.
     */
    private function readable(string $field, mixed $value): string
    {
        $table = match ($field) {
            'account_type' => 'mdl_accounts_types',
            'sector'       => 'mdl_accounts_sectors',
            'country'      => 'mdl_accounts_countries',
            default        => null,
        };

        return ($table ? $this->legacyName($table, $value) : null) ?? (string) $value;
    }

    /**
     * A field's value, or null when the old system meant "nobody said".
     *
     * Zero is the difference between a fact and a default. The old schema has
     * no null for a count, so an account nobody filled in reads as zero
     * branches and zero employees — and a merge that treats that as a value
     * keeps the empty half and throws away the thousand employees somebody
     * actually recorded.
     */
    private function present(string $field, mixed $value): ?string
    {
        if (in_array($field, self::COUNT_FIELDS, true)) {
            return (is_numeric($value) && (int) $value > 0) ? (string) (int) $value : null;
        }

        return $this->text($value);
    }

    /** Point another legacy id at a record that already exists. */
    private function mapAlso(string $table, int $sourceId, string $model, int $targetId, string $note): void
    {
        PlmImportMap::updateOrCreate(
            ['organization_id' => $this->orgId, 'source_table' => $table, 'source_id' => $sourceId],
            ['target_type' => $model, 'target_id' => $targetId, 'note' => $note],
        );
    }

    /** A legacy lookup row's name, by id. */
    private function legacyName(string $table, mixed $id): ?string
    {
        if (! $id || ! is_numeric($id)) {
            return null;
        }

        return $this->legacy()->table($table)->where('id', (int) $id)->value('name') ?: null;
    }

    /**
     * Match one of our lookup rows by name, within this organisation.
     *
     * By name and not by id: both systems number their sectors from one, and
     * the numbers mean different things. Silently reusing them would file
     * hospitals under energy.
     */
    private function lookup(string $table, ?string $name, string $what): ?int
    {
        if (! $name) {
            return null;
        }

        $id = DB::table($table)
            ->where('organization_id', $this->orgId)
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->value('id');

        if (! $id) {
            $this->unmapped[$what][$name] = ($this->unmapped[$what][$name] ?? 0) + 1;
        }

        return $id;
    }

    private function country(?string $name): ?string
    {
        if (! $name) {
            return null;
        }

        $code = self::COUNTRIES[mb_strtolower(trim($name))] ?? null;

        if (! $code) {
            $this->unmapped['country'][$name] = ($this->unmapped['country'][$name] ?? 0) + 1;
        }

        return $code;
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        // The dump writes unset values as the four letters N-U-L-L.
        return ($value === '' || mb_strtoupper($value) === 'NULL') ? null : $value;
    }

    private function date(mixed $value): ?string
    {
        $value = $this->text($value);

        return ($value === null || str_starts_with($value, '0000')) ? null : substr($value, 0, 10);
    }

    private function number(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function legacy(): \Illuminate\Database\ConnectionInterface
    {
        return DB::connection('plm');
    }

    private function note(string $kind, int $id, string $message): void
    {
        $this->unmapped['held back'][ucfirst($kind) . " {$id} " . $message] = 1;
    }

    private function summarise(string $label, array $counts): void
    {
        $parts = [];

        foreach ($counts as $key => $value) {
            $parts[] = "{$value} {$key}";
        }

        $this->line("  <options=bold>{$label}</>: " . implode(', ', $parts));
    }

    private function reportUnmapped(): void
    {
        if (! $this->unmapped) {
            $this->newLine();
            $this->info('Everything mapped. Nothing was left unset.');

            return;
        }

        $this->newLine();
        $this->warn('Left unset, and why:');

        foreach ($this->unmapped as $what => $values) {
            foreach ($values as $value => $count) {
                $this->line($what === 'held back'
                    ? "  · {$value}"
                    : "  · {$what} \"{$value}\" has no match here ({$count})");
            }
        }
    }
}
