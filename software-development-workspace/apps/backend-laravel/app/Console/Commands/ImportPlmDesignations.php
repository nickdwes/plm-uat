<?php

namespace App\Console\Commands;

use App\Models\Designation;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\PlmImportMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bring the job titles across from the old PLM, and put people back in them.
 *
 * The designations were lost with the database and nothing recreates them:
 * no seeder writes one, and ImportPlmStaff only ever *matches* a designation
 * that already exists — it returns null and counts the miss. So the staff
 * import restored a hundred and twenty-three people with no job title between
 * them, and the org chart, the directory and the availability rota all show a
 * blank where the role should be.
 *
 * ── There is no designation table over there ──
 *
 * The old system kept the job title as free text on the employee row
 * (`mdl_hr_employees.designation`). So the roster here is the set of distinct
 * titles people actually held, which is the only definition of the role list
 * the legacy data supports. Titles are matched case-insensitively and the
 * commonest spelling of each wins: "Network Engineer" and "network engineer"
 * are one job, and creating both would split a team down the middle for
 * reasons nobody could see on screen.
 *
 * ── What it cannot fill ──
 *
 * A job description, the skills a role wants and the certifications it
 * requires are new here and are in no legacy column. They are left null and
 * reported as such rather than guessed from the title — a plausible job
 * description nobody wrote is worse than an empty one, because it reads as
 * policy.
 *
 * Rehearses by default; --apply commits. Idempotent: a designation is matched
 * on its title within the organisation, so a second run adopts what the first
 * one wrote.
 */
class ImportPlmDesignations extends Command
{
    protected $signature = 'import:plm-designations
        {--org=konvergenz : Slug of the organisation to import into}
        {--apply : Write the changes. Without this it only reports.}';

    protected $description = 'Recreate the job titles from the old PLM and reattach people to them';

    /** Domains whose staff are ours. Mirrors ImportPlmStaff. */
    private const OUR_DOMAINS = ['kns.co.ke', 'knscoke.onmicrosoft.com', 'konvergenz.co.ug'];

    private bool $apply = false;
    private int $orgId;

    public function handle(): int
    {
        $this->apply = (bool) $this->option('apply');

        $org = Organization::where('slug', $this->option('org'))->first();

        if (! $org) {
            $this->error("No organisation with the slug '{$this->option('org')}'.");

            return self::FAILURE;
        }

        $this->orgId = $org->id;

        try {
            DB::connection('plm')->getPdo();
        } catch (\Throwable $e) {
            $this->error('The legacy PLM database is not reachable. Set PLM_DB_HOST, PLM_DB_DATABASE, '
                . 'PLM_DB_USERNAME and PLM_DB_PASSWORD in the backend .env first.');

            return self::FAILURE;
        }

        $rows = DB::connection('plm')->table('mdl_hr_employees as e')
            ->join('mdl_user as u', 'u.id', '=', 'e.user')
            ->whereNotNull('e.designation')
            ->where('e.designation', '<>', '')
            ->get(['e.id', 'e.designation', 'u.email']);

        /** lower(title) => ['label' => commonest spelling, 'spellings' => [...], 'legacy' => [ids]] */
        $titles = [];
        $foreign = 0;

        foreach ($rows as $row) {
            // Somebody else's staff carry somebody else's job titles.
            if (! in_array($this->domain($row->email), self::OUR_DOMAINS, true)) {
                $foreign++;
                continue;
            }

            $title = trim(preg_replace('/\s+/u', ' ', (string) $row->designation));

            if ($title === '') {
                continue;
            }

            $key = mb_strtolower($title);

            $titles[$key]['spellings'][$title] = ($titles[$key]['spellings'][$title] ?? 0) + 1;
            $titles[$key]['legacy'][] = $row->id;
        }

        if (! $titles) {
            $this->warn('The old system holds no job titles for anybody on our domains.');

            return self::SUCCESS;
        }

        ksort($titles);

        $created = 0;
        $adopted = 0;
        $attached = 0;
        $summary = [];

        foreach ($titles as $key => $group) {
            // The commonest spelling, so a stray lower-case entry does not
            // decide how the role is written everywhere.
            arsort($group['spellings']);
            $label = array_key_first($group['spellings']);

            [$id, $isNew] = $this->designation($label);

            $isNew ? $created++ : $adopted++;

            $moved = $this->attach($group['legacy'], $id);
            $attached += $moved;

            $summary[] = [
                $label,
                $isNew ? 'created' : 'existing',
                count($group['spellings']) > 1 ? implode(' / ', array_keys($group['spellings'])) : '',
                count($group['legacy']),
                $moved,
            ];
        }

        $this->table(['Job title', 'Row', 'Other spellings folded in', 'Held by', 'Attached'], $summary);

        $this->newLine();
        $this->line('Job titles created:  ' . $created);
        $this->line('Job titles existing: ' . $adopted);
        $this->line('People attached:     ' . $attached);

        if ($foreign > 0) {
            $this->newLine();
            $this->warn("{$foreign} legacy rows belong to people outside our domains and were skipped.");
        }

        $this->newLine();
        $this->warn('Job descriptions, desired skills and certifications are new fields with no legacy '
            . 'column behind them. They are left empty rather than guessed from the title, and need '
            . 'filling in on the Designations screen.');

        if (! $this->apply) {
            $this->newLine();
            $this->info('Rehearsal only. Nothing was written. Re-run with --apply to commit.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: ?int, 1: bool}
     */
    private function designation(string $title): array
    {
        $existing = Designation::withoutGlobalScope('organization')
            ->where('organization_id', $this->orgId)
            ->whereRaw('lower(trim(title)) = ?', [mb_strtolower(trim($title))])
            ->first();

        if ($existing) {
            return [(int) $existing->id, false];
        }

        if (! $this->apply) {
            return [null, true];
        }

        $created = Designation::create([
            'organization_id' => $this->orgId,
            'title'           => $title,
        ]);

        return [(int) $created->id, true];
    }

    /**
     * Put the people who held this title back into it.
     *
     * Reached through plm_import_map, which already links every legacy row to
     * the employee it became — so this attaches the same people the staff
     * import created rather than matching on a name.
     *
     * @param  array<int, int|string>  $legacyIds
     */
    private function attach(array $legacyIds, ?int $designationId): int
    {
        $employeeIds = PlmImportMap::query()
            ->where('organization_id', $this->orgId)
            ->where('source_table', 'mdl_hr_employees')
            ->whereIn('source_id', $legacyIds)
            ->pluck('target_id');

        if ($employeeIds->isEmpty()) {
            return 0;
        }

        $needing = Employee::whereIn('id', $employeeIds)
            ->where(fn ($q) => $q->whereNull('designation_id')->orWhere('designation_id', '!=', $designationId ?? 0))
            ->pluck('id');

        if ($this->apply && $designationId !== null && $needing->isNotEmpty()) {
            Employee::whereIn('id', $needing)->update(['designation_id' => $designationId]);
        }

        return $needing->count();
    }

    private function domain(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }

        return mb_strtolower(trim(explode('@', $email)[1]));
    }
}
