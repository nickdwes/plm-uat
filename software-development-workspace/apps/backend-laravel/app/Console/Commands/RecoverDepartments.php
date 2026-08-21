<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Put the departments back, and the people back in them.
 *
 * The departments were built by hand in the Organisation screen and were lost
 * with the database. Nothing recreates them: the seeders have never written a
 * department, and ImportPlmStaff only ever *matches* one that already exists,
 * so re-running the import restored the people and left every one of them
 * without a department.
 *
 * ── Where the answer comes from ──
 *
 * Each imported person carries a snapshot of the legacy row they came from,
 * and that snapshot holds their old business unit. That is the record of which
 * department somebody was in, it survived independently of the departments
 * themselves, and it is the same field ImportPlmStaff read the first time. So
 * this is not a guess about who worked where — it is the original source, read
 * again.
 *
 * ── What it cannot recover ──
 *
 * A department is more than a name. Codes, cost centres, the parent hierarchy,
 * the functional unit above it and the lead below it were all typed in and
 * none of them are in the snapshot. Names and membership come back; the rest
 * has to be re-entered, and the summary says so rather than inventing values
 * that would look authoritative on screen.
 *
 * Codes are the one exception, and only because the column is unique per
 * organisation and the screen expects something there. They are derived from
 * the name, reported as derived, and meant to be overwritten.
 *
 * Rehearses by default; --apply commits. Idempotent — a department is matched
 * on its name within the organisation, so a second run adopts the rows the
 * first one wrote instead of duplicating them.
 */
class RecoverDepartments extends Command
{
    protected $signature = 'recover:departments {--apply : Write the changes. Without this it only reports.}';

    protected $description = 'Rebuild the departments from the imported staff snapshots and reattach people to them';

    /** Departments this run created, so a rehearsal can still report membership. */
    private array $pretend = [];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        /*
         * The snapshot is keyed to the map row, and the map row names the
         * employee. Reading both together means a person whose employee record
         * was since deleted simply drops out rather than becoming an orphaned
         * department member.
         */
        $rows = DB::table('plm_import_map as m')
            ->join('employees as e', 'e.id', '=', 'm.target_id')
            ->where('m.source_table', 'mdl_hr_employees')
            ->whereNull('e.deleted_at')
            ->get(['e.id as employee_id', 'e.primary_organization_id as org_id',
                   'e.department_id', 'm.source_snapshot']);

        if ($rows->isEmpty()) {
            $this->error('No imported staff snapshots found — there is nothing to read the old units from.');

            return self::FAILURE;
        }

        /** Organisation => department name => list of employee ids. */
        $wanted     = [];
        $noUnit     = 0;
        $tooLong    = [];

        foreach ($rows as $row) {
            $unit = trim((string) (json_decode($row->source_snapshot, true)['bu'] ?? ''));

            /*
             * Fifty-two people had no unit in the old system. That is a fact
             * about the legacy data, not a failure here, and it is counted
             * rather than filled in — an invented department is worse than an
             * empty one, because nobody goes looking for it.
             */
            if ($unit === '') {
                $noUnit++;
                continue;
            }

            $name = ImportPlmStaff::DEPARTMENT_ALIASES[mb_strtolower($unit)] ?? $unit;

            // The screen caps a department name at 80 characters. Nothing in
            // the legacy data reaches it, but a name that would be refused on
            // edit should not be written here either.
            if (mb_strlen($name) > 80) {
                $tooLong[$name] = ($tooLong[$name] ?? 0) + 1;
                continue;
            }

            $wanted[$row->org_id][$name][] = $row->employee_id;
        }

        $created = 0;
        $adopted = 0;
        $moved   = 0;
        $summary = [];

        foreach ($wanted as $orgId => $departments) {
            $org = Organization::find($orgId);
            ksort($departments);

            foreach ($departments as $name => $employeeIds) {
                [$id, $isNew] = $this->department((int) $orgId, $name, $apply);

                $isNew ? $created++ : $adopted++;

                /*
                 * Only the people who are not already there. Reporting "123
                 * attached" on a second run when nothing changed would make an
                 * idempotent command look like it did the work twice.
                 */
                $toMove = Employee::whereIn('id', $employeeIds)
                    ->where(fn ($q) => $q->whereNull('department_id')->orWhere('department_id', '!=', $id))
                    ->pluck('id');

                if ($apply && $toMove->isNotEmpty() && $id !== null) {
                    Employee::whereIn('id', $toMove)->update(['department_id' => $id]);
                }

                $moved += $toMove->count();

                $summary[] = [
                    $org?->name ?? "org {$orgId}",
                    $name,
                    $isNew ? 'created' : 'existing',
                    count($employeeIds),
                    $toMove->count(),
                ];
            }
        }

        $this->table(['Organisation', 'Department', 'Row', 'People', 'Attached'], $summary);

        $this->newLine();
        $this->line("Departments created:  {$created}");
        $this->line("Departments existing: {$adopted}");
        $this->line("People attached:      {$moved}");

        if ($noUnit > 0) {
            $this->newLine();
            $this->warn("{$noUnit} people had no business unit in the old system and are left without a "
                . 'department. Nothing here can say where they belonged.');
        }

        foreach ($tooLong as $name => $count) {
            $this->warn("Skipped '{$name}' ({$count} people) — longer than the 80 characters the screen allows.");
        }

        $this->newLine();
        $this->warn('Codes are derived from the name. Cost centres, the parent hierarchy, the functional '
            . 'unit and the department lead were never in the snapshot and are not recoverable — they need '
            . 're-entering by hand.');

        if (! $apply) {
            $this->newLine();
            $this->info('Rehearsal only. Nothing was written. Re-run with --apply to commit.');
        }

        return self::SUCCESS;
    }

    /**
     * The department by that name in that organisation, creating it if absent.
     *
     * @return array{0: ?int, 1: bool}  The id (null while rehearsing a new row) and whether it is new.
     */
    private function department(int $orgId, string $name, bool $apply): array
    {
        $existing = Department::where('organization_id', $orgId)
            ->whereRaw('lower(trim(name)) = ?', [mb_strtolower(trim($name))])
            ->first();

        if ($existing) {
            return [(int) $existing->id, false];
        }

        if (isset($this->pretend[$orgId][$name])) {
            return [null, false];
        }

        if (! $apply) {
            $this->pretend[$orgId][$name] = true;

            return [null, true];
        }

        $department = Department::create([
            'organization_id' => $orgId,
            'name'            => $name,
            'code'            => $this->code($orgId, $name),
            'is_active'       => true,
        ]);

        return [(int) $department->id, true];
    }

    /**
     * A short code from the name, unique within the organisation.
     *
     * The column is unique per organisation and the screen expects a value, so
     * something has to go here. Initials of the significant words, which gives
     * DCP for Data Centre & Power and NSOC for NSOC; a numeric suffix settles
     * the collisions rather than letting the insert fail.
     */
    private function code(int $orgId, string $name): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter($words, fn ($w) => ! in_array(mb_strtolower($w), ['and', 'of', 'the'], true)));

        // A single word is its own code — NSOC stays NSOC and Telecoms stays
        // TELECOMS. Cutting it to initials would leave a one-letter code, and
        // cutting it short leaves TELECO, which reads as a typo.
        $base = count($words) === 1
            ? mb_strtoupper(mb_substr($words[0], 0, 8))
            : mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 0, 5))));

        $base = $base !== '' ? $base : 'DEPT';
        $code = $base;
        $n    = 1;

        while (Department::where('organization_id', $orgId)->where('code', $code)->exists()) {
            $code = $base . ++$n;
        }

        return $code;
    }
}
