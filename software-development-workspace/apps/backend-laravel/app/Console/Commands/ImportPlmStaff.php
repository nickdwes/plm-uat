<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\PlmImportMap;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Bring the staff across from the old PLM.
 *
 * Two hundred and seventeen people, against the thirty-four invented ones that
 * were standing in for them. Everything the desk does — assignment, escalation,
 * the org chart, every figure in a service review — is about these people, and
 * until they exist the application is a demonstration.
 *
 * ── Which company somebody works for is read from their address ──
 *
 * The old system was one company with business units; this is four
 * organisations with a data boundary between them, and putting somebody in the
 * wrong one is not a tidy-up afterwards. The business unit cannot answer it:
 * "Healthcare Technology & Innovation" holds twenty-seven Tiberbu people, ten
 * Konvergenz and one from Visibility. The email domain can, and does.
 *
 * ── A department, and no business unit ──
 *
 * An employee here has a department and nothing else: business units exist
 * only as the parent of a practice and as somewhere a support ticket can be
 * routed, with no column joining a person to one. So the old business unit
 * becomes a department where the two can be matched, and the rest are counted
 * and named. Filing people against a unit they are not related to would be
 * writing something nothing can read.
 *
 * ── Leavers keep their record and lose their login ──
 *
 * Fifty-one of the logins are switched off. Dropping those people would leave
 * holes in the reporting lines of the ones who remain — five people report to
 * somebody who has left — so they come across as employees who have exited,
 * with no account and no way in.
 *
 * ── No password comes across ──
 *
 * Not one. Every account is created with a random secret nobody holds and
 * marked pending, so the only route in is a reset the person requests. An
 * import that carries hashes from a system being retired for its security is
 * an import that carries the reason it is being retired.
 */
class ImportPlmStaff extends Command
{
    protected $signature = 'plm:import-staff
        {--org=konvergenz : Organisation slug to import into}
        {--apply : Actually write. Without it, nothing changes}';

    protected $description = 'Import employees and their reporting lines from the old PLM';

    /**
     * The addresses that mean this company.
     *
     * Named rather than inferred. A rule like "anything that is not tiberbu"
     * silently adopts every address nobody has thought about yet, and the
     * people this gets wrong are the ones nobody notices are missing.
     */
    private const OUR_DOMAINS = [
        'kns.co.ke',
        'knscoke.onmicrosoft.com',
        'konvergenz.co.ug',
    ];

    /**
     * Legacy business units, as this organisation names its departments.
     *
     * Written out rather than matched loosely. The old names are longer than
     * ours — "Data Centre & Power" against "Data Centre" — and no amount of
     * fuzzy comparison distinguishes a real abbreviation from a coincidence:
     * "Identity Security & Visibility" is not the Visibility company, and a
     * matcher clever enough to bridge the first gap is clever enough to fall
     * into the second.
     *
     * Only the unambiguous ones are here. "Project Management" is left out
     * because both "PMO" and "Projects Management office" exist on our side,
     * and picking one silently splits a team in half.
     */
    private const DEPARTMENT_ALIASES = [
        'cloud platforms solutions & iam'          => 'Cloud Platforms',
        'data centre & power'                      => 'Data Centre',
        'enterprise compute & business resilliency' => 'Ent. Compute',
        'ent zt networks'                          => 'NetSec',
        'telecommunications'                       => 'Telecoms',
    ];

    /** Legacy employment types, as ours name them. */
    private const EMPLOYMENT = [
        'permanent' => 'full_time',
        'contract'  => 'contract',
        'intern'    => 'intern',
        'trainee'   => 'attachment',
    ];

    private bool $apply = false;
    private int $orgId;
    private array $unmapped = [];

    /** People whose employment type the old system never recorded. */
    private int $guessed = 0;

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

        try {
            $this->legacy()->getPdo();
        } catch (\Throwable $e) {
            $this->error('Cannot reach the old PLM database: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line($this->apply
            ? "Importing into <options=bold>{$org->name}</>."
            : "Rehearsing against <options=bold>{$org->name}</>. Nothing will be written — pass --apply to commit.");
        $this->newLine();

        $run = function () {
            $people = $this->importPeople();
            $lines  = $this->linkReportingLines();

            $this->summarise('People', $people);
            $this->summarise('Reporting lines', $lines);
        };

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

    // ── The people ────────────────────────────────────────────────────────────

    private function importPeople(): array
    {
        $rows = $this->legacy()->table('mdl_hr_employees as e')
            ->join('mdl_user as u', 'u.id', '=', 'e.user')
            ->orderBy('e.id')
            ->get([
                'e.id', 'e.user', 'e.bu', 'e.designation', 'e.answers_to', 'e.also_answers_to',
                'e.employee_type', 'e.gender', 'e.employement_date', 'e.enrol_date',
                'u.first_name', 'u.last_name', 'u.email', 'u.phone', 'u.address', 'u.is_active',
            ]);

        $made = 0;
        $updated = 0;
        $elsewhere = 0;
        $accounts = 0;
        $leavers = 0;
        $undated = 0;

        foreach ($rows as $row) {
            $domain = $this->domain($row->email);

            if (! in_array($domain, self::OUR_DOMAINS, true)) {
                /*
                 * Somebody else's staff. Counted by domain rather than by name
                 * — ninety-one people is a list nobody reads, and the useful
                 * fact is which companies they belong to.
                 */
                $this->unmapped['not ours'][$domain ?: '(no address)'] =
                    ($this->unmapped['not ours'][$domain ?: '(no address)'] ?? 0) + 1;
                $elsewhere++;

                continue;
            }

            /*
             * A hire date cannot be invented. It is the date leave accrues
             * from and length of service is counted from, and a plausible one
             * is indistinguishable from a real one once it is in the record.
             * The column is required, so the three people who have neither
             * date in the old system are named instead — this import can be
             * run again once somebody fills them in.
             */
            if (! $this->date($row->employement_date ?? $row->enrol_date)) {
                $this->unmapped['held back'][
                    trim("{$row->first_name} {$row->last_name}") . " ({$row->email}) has no hire date"
                ] = 1;
                $undated++;

                continue;
            }

            $active = (int) $row->is_active === 1;

            $employee = $this->upsertEmployee($row, $active);

            $employee->wasRecentlyCreated ? $made++ : $updated++;

            if ($active) {
                $this->upsertAccount($row, $employee);
                $accounts++;
            } else {
                $leavers++;
            }
        }

        return [
            'read' => $rows->count(), 'created' => $made, 'updated' => $updated,
            'with a login' => $accounts, 'leavers (no login)' => $leavers,
            'other companies' => $elsewhere, 'no hire date' => $undated,
        ];
    }

    private function upsertEmployee(object $row, bool $active): Employee
    {
        $existing = PlmImportMap::resolve($this->orgId, 'mdl_hr_employees', $row->id);

        $employee = ($existing ? Employee::find($existing->target_id) : null)
            /*
             * Failing that, somebody already here with the same work address.
             * Two accounts for one person is worse than any field being stale,
             * and the admin who set this system up is in the old one too.
             */
            ?? Employee::where('work_email', $row->email)->first()
            ?? new Employee();

        $unit = $this->legacyName('mdl_bu', $row->bu);

        /*
         * Written before the filter: array_filter drops the falsy, and
         * `is_shared => false` is an answer rather than an absence.
         */
        $attributes = array_filter([
            'employee_number'         => $employee->employee_number ?: $this->nextNumber(),
            'primary_organization_id' => $this->orgId,
            'first_name'              => trim((string) $row->first_name) ?: 'Unknown',
            'last_name'               => trim((string) $row->last_name) ?: '',
            'work_email'              => $this->text($row->email),
            'phone'                   => $this->text($row->phone),
            'address'                 => $this->text($row->address),
            'designation_id'          => $this->designation($row->designation),
            // Both, where each has a name to match. The old "business unit"
            // is called a department here as often as it is called a unit.
            'department_id'           => $this->department($unit),
            'employment_type'         => $this->employmentType($row->employee_type),
            'date_hired'              => $this->date($row->employement_date ?? $row->enrol_date),
            /*
             * A second reporting line is what the org chart draws as shared.
             * A hundred and eight people have one, which is the matrix the old
             * system recorded and nothing ever displayed.
             */
            'is_shared'               => $this->text($row->also_answers_to) !== null
                && (int) $row->also_answers_to !== 0,
            'status'                  => $active ? 'active' : 'exited',
        ], fn ($v) => $v !== null);

        $employee->forceFill($attributes)->save();

        if (! $existing) {
            PlmImportMap::updateOrCreate(
                ['organization_id' => $this->orgId, 'source_table' => 'mdl_hr_employees', 'source_id' => $row->id],
                ['target_type' => Employee::class, 'target_id' => $employee->id,
                 'source_snapshot' => ['email' => $row->email, 'bu' => $unit, 'active' => $row->is_active]],
            );
        }

        return $employee;
    }

    /**
     * A login, for somebody who still works here.
     *
     * Existing accounts are matched and left holding their own password. The
     * import brings people across; it does not lock anybody out who is already
     * signing in.
     */
    private function upsertAccount(object $row, Employee $employee): void
    {
        $user = User::where('email', $row->email)->first();

        if (! $user) {
            $user = User::create([
                'name'     => trim("{$row->first_name} {$row->last_name}"),
                'email'    => $row->email,
                // Random, and nobody holds it. The way in is a reset.
                'password' => Hash::make(Str::random(48)),
                'account_status' => User::STATUS_PENDING,
            ]);
        }

        if ($employee->user_id !== $user->id) {
            $employee->forceFill(['user_id' => $user->id])->save();
        }
    }

    // ── Who answers to whom ───────────────────────────────────────────────────

    /**
     * The reporting lines, once everybody exists.
     *
     * A second pass on purpose. A tree cannot be built in one, because the
     * first person imported answers to somebody who has not been created yet —
     * and a manager resolved to null at that moment is a hole nobody notices
     * until the org chart is missing a branch.
     */
    private function linkReportingLines(): array
    {
        $rows = $this->legacy()->table('mdl_hr_employees')
            ->whereNotNull('answers_to')->where('answers_to', '<>', 0)
            ->get(['id', 'answers_to']);

        $linked = 0;
        $managerElsewhere = 0;

        foreach ($rows as $row) {
            $person  = PlmImportMap::resolve($this->orgId, 'mdl_hr_employees', $row->id);
            $manager = PlmImportMap::resolve($this->orgId, 'mdl_hr_employees', $row->answers_to);

            if (! $person) {
                continue;
            }

            if (! $manager) {
                /*
                 * Their manager works for one of the other companies, or is one
                 * of the people held back. Left unset rather than pointed at
                 * somebody plausible — a wrong reporting line is read as fact
                 * by leave approval and by every escalation path.
                 */
                $managerElsewhere++;

                continue;
            }

            Employee::whereKey($person->target_id)->update(['reports_to' => $manager->target_id]);
            $linked++;
        }

        if ($managerElsewhere > 0) {
            $this->unmapped['held back']["{$managerElsewhere} people report to somebody who was not imported — "
                . 'their reporting line is unset'] = 1;
        }

        return ['read' => $rows->count(), 'linked' => $linked, 'manager not here' => $managerElsewhere];
    }

    // ── Shared ────────────────────────────────────────────────────────────────

    /** The department a legacy business unit means here, if we can say. */
    private function department(?string $unit): ?int
    {
        if (! $unit) {
            return null;
        }

        $name = self::DEPARTMENT_ALIASES[mb_strtolower(trim($unit))] ?? $unit;

        return $this->lookup('departments', $name, 'department', $unit);
    }

    private function designation(?string $title): ?int
    {
        $title = $this->text($title);

        if (! $title) {
            return null;
        }

        $id = DB::table('designations')
            ->where('organization_id', $this->orgId)
            ->whereRaw('lower(title) = ?', [mb_strtolower(trim($title))])
            ->value('id');

        if (! $id) {
            $this->unmapped['designation'][$title] = ($this->unmapped['designation'][$title] ?? 0) + 1;
        }

        return $id;
    }

    /**
     * Match one of our lookup rows by name, within this organisation.
     *
     * Deliberately refuses when a name is not unique. Two rows called "Finance"
     * exist, and attaching half the finance team to one and half to the other
     * is a split nobody would ever spot on screen.
     */
    private function lookup(string $table, ?string $name, string $what, ?string $reportAs = null): ?int
    {
        if (! $name) {
            return null;
        }

        $label = $reportAs ?? $name;

        $matches = DB::table($table)
            ->where('organization_id', $this->orgId)
            ->whereRaw('lower(trim(name)) = ?', [mb_strtolower(trim($name))])
            ->pluck('id');

        if ($matches->count() === 1) {
            return (int) $matches->first();
        }

        $key = trim($label) . ($matches->count() > 1 ? ' (we have more than one by that name)' : '');

        $this->unmapped[$what][$key] = ($this->unmapped[$what][$key] ?? 0) + 1;

        return null;
    }

    /**
     * Permanent, contract, intern — or a guess the schema forces.
     *
     * Fifty-three of the two hundred and seventeen have none recorded, and the
     * column here cannot be null. Permanent and contract are near enough a
     * coin flip in the old data that defaulting is genuinely a guess, so the
     * count is reported with the reason somebody should care: employment type
     * decides contract end dates and how payroll treats a person.
     */
    private function employmentType(mixed $legacyId): string
    {
        $name = mb_strtolower((string) $this->legacyName('mdl_hr_etypes', $legacyId));

        if (isset(self::EMPLOYMENT[$name])) {
            return self::EMPLOYMENT[$name];
        }

        $this->guessed++;

        return 'full_time';
    }

    /** The next number in the sequence the one real record already uses. */
    private function nextNumber(): string
    {
        static $next = null;

        if ($next === null) {
            $highest = Employee::where('employee_number', 'like', 'EMP-%')
                ->orderByRaw('length(employee_number) desc, employee_number desc')
                ->value('employee_number');

            $next = ((int) preg_replace('/\D/', '', (string) $highest)) + 1;
        }

        return 'EMP-' . str_pad((string) $next++, 5, '0', STR_PAD_LEFT);
    }

    private function domain(?string $email): ?string
    {
        $email = $this->text($email);

        return $email && str_contains($email, '@')
            ? mb_strtolower(trim(substr(strrchr($email, '@'), 1)))
            : null;
    }

    private function legacyName(string $table, mixed $id): ?string
    {
        if (! $id || ! is_numeric($id)) {
            return null;
        }

        return $this->legacy()->table($table)->where('id', (int) $id)->value('name') ?: null;
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return ($value === '' || mb_strtoupper($value) === 'NULL') ? null : $value;
    }

    private function date(mixed $value): ?string
    {
        $value = $this->text($value);

        return ($value === null || str_starts_with($value, '0000')) ? null : substr($value, 0, 10);
    }

    private function legacy(): \Illuminate\Database\ConnectionInterface
    {
        return DB::connection('plm');
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
        if (! $this->unmapped && $this->guessed === 0) {
            $this->newLine();
            $this->info('Everything mapped. Nothing was left unset.');

            return;
        }

        if ($this->guessed > 0) {
            $this->newLine();
            $this->warn("{$this->guessed} people had no employment type in the old system and were "
                . 'recorded as full time. Check them before payroll runs — the type decides contract '
                . 'end dates and how somebody is paid.');
        }

        foreach ($this->unmapped as $what => $values) {
            $this->newLine();

            $this->warn(match ($what) {
                'not ours'  => 'Left for their own company:',
                'held back' => 'Held back:',
                default     => "No {$what} here by that name — left unset:",
            });

            arsort($values);

            foreach ($values as $value => $count) {
                $this->line(in_array($what, ['held back'], true)
                    ? "  · {$value}"
                    : "  · {$value} — {$count}");
            }
        }
    }
}
