<?php

namespace App\Console\Commands;

use App\Support\Account\DefaultReferenceData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give every organisation the reference lists it cannot work without.
 *
 * Account types, sectors and the opportunity phases exist as tables rather than
 * free text so they can be grouped on — and an empty list is worse than free
 * text, because nothing can be classified at all.
 *
 * **Why this command exists.** Two migrations already back-fill these, but they
 * only reach organisations that existed the moment they ran, and
 * `OrganizationController::store` only seeds ones created through it. Every
 * organisation here was created by an importer after those migrations had gone
 * by, so all four ended up with nothing — and the symptoms surfaced far from
 * the cause: no customer could be given an industry, no deal could be given a
 * phase, and the deal pipeline's Qualification stage was unreachable because
 * its stage list was empty.
 *
 * Idempotent, because `seedFor()` is: `insertOrIgnore` against the
 * (organisation, name) uniques, so running it twice never disturbs a list
 * somebody has since edited.
 */
class SeedReferenceData extends Command
{
    protected $signature = 'reference:seed
                            {--org= : One organisation slug or id; every organisation by default}
                            {--dry-run : Report what is missing without writing}';

    protected $description = 'Back-fill account types, sectors and opportunity phases for organisations that have none';

    public function handle(): int
    {
        $organizations = DB::table('organizations')
            ->when($this->option('org'), function ($q, $org) {
                return is_numeric($org) ? $q->where('id', (int) $org) : $q->where('slug', $org);
            })
            ->orderBy('id')
            ->get(['id', 'slug']);

        if ($organizations->isEmpty()) {
            $this->error('No organisation matched.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        foreach ($organizations as $org) {
            $before = $this->counts($org->id);

            if (! $dryRun) {
                DefaultReferenceData::seedFor((int) $org->id);
            }

            $after = $dryRun ? $before : $this->counts($org->id);

            $rows[] = [
                $org->slug,
                $this->change($before['types'], $after['types'], count(DefaultReferenceData::TYPES)),
                $this->change($before['sectors'], $after['sectors'], count(DefaultReferenceData::SECTORS)),
                $this->change($before['phases'], $after['phases'], count(DefaultReferenceData::OPPORTUNITY_PHASES)),
            ];
        }

        $this->table(['Organisation', 'Types', 'Sectors', 'Opportunity phases'], $rows);

        if ($dryRun) {
            $this->warn('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }

    /** @return array{types: int, sectors: int, phases: int} */
    private function counts(int $orgId): array
    {
        return [
            'types'   => DB::table('account_types')->where('organization_id', $orgId)->count(),
            'sectors' => DB::table('account_sectors')->where('organization_id', $orgId)->count(),
            'phases'  => DB::table('opportunity_phases')->where('organization_id', $orgId)->count(),
        ];
    }

    /** "0 → 10" when something landed, "10" when it was already there. */
    private function change(int $before, int $after, int $expected): string
    {
        if ($before === $after) {
            return $before >= $expected ? (string) $before : "{$before} (missing " . ($expected - $before) . ')';
        }

        return "{$before} → {$after}";
    }
}
