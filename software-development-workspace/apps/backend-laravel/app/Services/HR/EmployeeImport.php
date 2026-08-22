<?php

namespace App\Services\HR;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\JobGrade;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bringing people in from a spreadsheet.
 *
 * Keeps the habits the PLM importers established, because they were learned
 * the hard way: it rehearses by default, it is idempotent, and it never
 * invents a value it was not given.
 *
 * ── It will not create a department to put somebody in ──
 *
 * The failure that makes a bulk import worse than typing is a sheet with
 * "Netsec", "NetSec " and "Network Security" in it silently becoming three
 * departments, after which the org chart is wrong and nobody can see why. A
 * name that does not match an existing record leaves the field unset and is
 * reported, with a count, so somebody can decide whether it is a new
 * department or a typo. The same goes for job titles and grades.
 *
 * ── Matching, so a second run corrects rather than duplicates ──
 *
 * Employee number first, work email second. Both are how a person is referred
 * to outside this system, which is what makes them usable as a key by whoever
 * is preparing the sheet. A row matching neither is a new person.
 */
class EmployeeImport
{
    /**
     * The fields a sheet may set.
     *
     * `required` means required to create somebody, not to update them — a
     * sheet correcting phone numbers should not have to restate everybody's
     * hire date.
     *
     * @return array<string, array{label: string, group: string, required?: bool, sensitive?: bool, type?: string, values?: array<int, string>}>
     */
    public static function fields(): array
    {
        return [
            'employee_number' => ['label' => 'Employee No.',    'group' => 'Identity'],
            'first_name'      => ['label' => 'First Name',      'group' => 'Identity', 'required' => true],
            'last_name'       => ['label' => 'Last Name',       'group' => 'Identity', 'required' => true],
            'middle_name'     => ['label' => 'Middle Name',     'group' => 'Identity'],
            'preferred_name'  => ['label' => 'Preferred Name',  'group' => 'Identity'],

            'work_email'      => ['label' => 'Work Email',      'group' => 'Contact'],
            'personal_email'  => ['label' => 'Personal Email',  'group' => 'Contact', 'sensitive' => true],
            'phone'           => ['label' => 'Phone',           'group' => 'Contact'],
            'address'         => ['label' => 'Address',         'group' => 'Contact'],
            'city'            => ['label' => 'City',            'group' => 'Contact'],
            'country'         => ['label' => 'Country',         'group' => 'Contact'],

            'department'      => ['label' => 'Department',      'group' => 'Job', 'type' => 'lookup'],
            'designation'     => ['label' => 'Job Title',       'group' => 'Job', 'type' => 'lookup'],
            'job_grade'       => ['label' => 'Job Grade',       'group' => 'Job', 'type' => 'lookup'],
            'employment_type' => ['label' => 'Employment Type', 'group' => 'Job', 'required' => true,
                                  'type' => 'enum', 'values' => ['full_time', 'part_time', 'contract', 'intern', 'attachment', 'casual']],
            'status'          => ['label' => 'Status',          'group' => 'Job',
                                  'type' => 'enum', 'values' => ['active', 'exited', 'draft']],
            'date_hired'      => ['label' => 'Date Joined',     'group' => 'Job', 'required' => true, 'type' => 'date'],
            'probation_end_date' => ['label' => 'Probation Ends', 'group' => 'Job', 'type' => 'date'],
            'contract_end_date'  => ['label' => 'Contract Ends', 'group' => 'Job', 'type' => 'date'],

            'gender'          => ['label' => 'Gender',          'group' => 'Personal', 'sensitive' => true],
            'date_of_birth'   => ['label' => 'Date of Birth',   'group' => 'Personal', 'sensitive' => true, 'type' => 'date'],
            'marital_status'  => ['label' => 'Marital Status',  'group' => 'Personal', 'sensitive' => true],

            'national_id'     => ['label' => 'National ID',     'group' => 'Statutory', 'sensitive' => true],
            'passport_number' => ['label' => 'Passport No.',    'group' => 'Statutory', 'sensitive' => true],
            'tax_id'          => ['label' => 'KRA PIN',         'group' => 'Statutory', 'sensitive' => true],
            'nssf_number'     => ['label' => 'NSSF Number',     'group' => 'Statutory', 'sensitive' => true],
            'nhif_number'     => ['label' => 'SHA / NHIF Number', 'group' => 'Statutory', 'sensitive' => true],

            'basic_salary'        => ['label' => 'Basic Salary',        'group' => 'Pay', 'sensitive' => true, 'type' => 'decimal'],
            'house_allowance'     => ['label' => 'House Allowance',     'group' => 'Pay', 'sensitive' => true, 'type' => 'decimal'],
            'transport_allowance' => ['label' => 'Transport Allowance', 'group' => 'Pay', 'sensitive' => true, 'type' => 'decimal'],
            'medical_allowance'   => ['label' => 'Medical Allowance',   'group' => 'Pay', 'sensitive' => true, 'type' => 'decimal'],
            'other_allowances'    => ['label' => 'Other Allowances',    'group' => 'Pay', 'sensitive' => true, 'type' => 'decimal'],
        ];
    }

    public static function availableTo(?User $user, ?int $organizationId): array
    {
        $maySeeSensitive = PeopleReport::maySeeSensitive($user, $organizationId);

        return array_filter(
            self::fields(),
            fn ($f) => $maySeeSensitive || empty($f['sensitive']),
        );
    }

    /**
     * A guess at which sheet column is which field, so nobody maps thirty
     * columns by hand when the headings already say what they are.
     *
     * Only ever a suggestion — the caller confirms it, and a wrong guess is
     * corrected on screen rather than discovered afterwards in the data.
     *
     * @param  array<int, string>  $headings
     * @return array<string, string>  heading => field key
     */
    public static function suggest(array $headings, ?User $user, ?int $organizationId): array
    {
        $fields = self::availableTo($user, $organizationId);
        $out    = [];
        $taken  = [];

        foreach ($headings as $heading) {
            $needle = self::normalise($heading);

            if ($needle === '') {
                continue;
            }

            foreach ($fields as $key => $meta) {
                if (in_array($key, $taken, true)) {
                    continue;
                }

                if ($needle === self::normalise($meta['label']) || $needle === self::normalise($key)) {
                    $out[$heading] = $key;
                    $taken[] = $key;
                    break;
                }
            }
        }

        return $out;
    }

    private static function normalise(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($value))) ?? '';
    }

    /**
     * Read the sheet, and either report what would happen or do it.
     *
     * @param  array<int, array<int, mixed>>  $rows     Data rows, without the heading row.
     * @param  array<int, string>             $headings
     * @param  array<string, string>          $mapping  heading => field key
     */
    public function run(
        array $rows,
        array $headings,
        array $mapping,
        int $organizationId,
        ?User $user,
        bool $apply,
    ): array {
        $fields = self::availableTo($user, $organizationId);

        // A mapping naming a field this user may not write is dropped, not
        // obeyed. The picker is a convenience; this is the gate.
        $mapping = array_filter($mapping, fn ($key) => isset($fields[$key]));

        $lookups  = $this->lookups($organizationId);
        $problems = [];
        $unmatched = [];
        $created = 0;
        $updated = 0;
        $skipped = 0;

        $work = function () use (
            $rows, $headings, $mapping, $fields, $lookups, $organizationId, $apply,
            &$problems, &$unmatched, &$created, &$updated, &$skipped
        ) {
            foreach ($rows as $i => $raw) {
                // +2: the heading row, and spreadsheets count from one. The
                // number in a problem must be the number in the sheet.
                $line = $i + 2;

                $values = $this->readRow($raw, $headings, $mapping);

                if ($this->blank($values)) {
                    continue;
                }

                $existing = $this->match($values, $organizationId);

                $attributes = [];
                $rowProblem = null;

                foreach ($values as $key => $value) {
                    $meta = $fields[$key];

                    if (($meta['type'] ?? null) === 'lookup') {
                        $id = $this->resolveLookup($key, (string) $value, $lookups);

                        if ($id === null) {
                            /*
                             * Named and counted, never created. A sheet with
                             * three spellings of one department silently
                             * becoming three departments is the failure that
                             * makes a bulk import worse than typing.
                             */
                            $unmatched[$key][(string) $value] = ($unmatched[$key][(string) $value] ?? 0) + 1;
                            continue;
                        }

                        $attributes[$this->lookupColumn($key)] = $id;
                        continue;
                    }

                    $cast = $this->cast($value, $meta);

                    if ($cast === false) {
                        $rowProblem = "{$meta['label']}: '{$value}' is not a "
                            . (($meta['type'] ?? 'text') === 'enum'
                                ? 'recognised value (' . implode(', ', $meta['values']) . ')'
                                : ($meta['type'] ?? 'text'));
                        break;
                    }

                    $attributes[$key] = $cast;
                }

                if ($rowProblem) {
                    $problems[] = ['row' => $line, 'reason' => $rowProblem];
                    $skipped++;
                    continue;
                }

                if (! $existing) {
                    $missing = [];

                    foreach ($fields as $key => $meta) {
                        if (! empty($meta['required']) && ($attributes[$key] ?? null) === null) {
                            $missing[] = $meta['label'];
                        }
                    }

                    if ($missing) {
                        $problems[] = [
                            'row'    => $line,
                            'reason' => 'New person, but no ' . implode(', ', $missing)
                                . ' — the record cannot be created without it.',
                        ];
                        $skipped++;
                        continue;
                    }
                }

                if (! $apply) {
                    $existing ? $updated++ : $created++;
                    continue;
                }

                if ($existing) {
                    $existing->fill($attributes)->save();
                    $updated++;
                } else {
                    Employee::create($attributes + [
                        'primary_organization_id' => $organizationId,
                        'employee_number' => $attributes['employee_number'] ?? $this->nextNumber(),
                        'status' => $attributes['status'] ?? 'active',
                    ]);
                    $created++;
                }
            }
        };

        $apply ? DB::transaction($work) : $work();

        ksort($unmatched);

        return [
            'applied'   => $apply,
            'rows_read' => count($rows),
            'created'   => $created,
            'updated'   => $updated,
            'skipped'   => $skipped,
            // Capped: a broken sheet produces one problem per row, and five
            // hundred identical complaints is not a more useful answer.
            'problems'  => array_slice($problems, 0, 50),
            'problem_count' => count($problems),
            'unmatched' => $unmatched,
        ];
    }

    /** @return array<string, mixed> */
    private function readRow(array $raw, array $headings, array $mapping): array
    {
        $out = [];

        foreach ($headings as $i => $heading) {
            $key = $mapping[$heading] ?? null;

            if (! $key) {
                continue;
            }

            $value = $raw[$i] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            if ($value === null || $value === '') {
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    private function blank(array $values): bool
    {
        return $values === [];
    }

    /**
     * The person this row is about, if they are already here.
     *
     * Employee number first, then work email. Both are how somebody is
     * referred to outside this system, which is what makes them usable as a
     * key by whoever prepares the sheet.
     */
    private function match(array $values, int $organizationId): ?Employee
    {
        $base = fn () => Employee::withoutGlobalScope('organization')
            ->where('primary_organization_id', $organizationId);

        if (! empty($values['employee_number'])) {
            $found = (clone $base())
                ->whereRaw('lower(trim(employee_number)) = ?', [mb_strtolower(trim((string) $values['employee_number']))])
                ->first();

            if ($found) {
                return $found;
            }
        }

        if (! empty($values['work_email'])) {
            return (clone $base())
                ->whereRaw('lower(trim(work_email)) = ?', [mb_strtolower(trim((string) $values['work_email']))])
                ->first();
        }

        return null;
    }

    /** @return array<string, array<string, int>> */
    private function lookups(int $organizationId): array
    {
        $byName = fn ($rows) => collect($rows)
            ->mapWithKeys(fn ($r) => [self::normalise((string) $r->name) => (int) $r->id])
            ->all();

        return [
            'department'  => $byName(Department::where('organization_id', $organizationId)->get(['id', 'name'])),
            'designation' => $byName(Designation::withoutGlobalScope('organization')
                ->where('organization_id', $organizationId)->get(['id', 'title as name'])),
            'job_grade'   => $byName(JobGrade::where('organization_id', $organizationId)->get(['id', 'name'])),
        ];
    }

    private function resolveLookup(string $field, string $value, array $lookups): ?int
    {
        return $lookups[$field][self::normalise($value)] ?? null;
    }

    private function lookupColumn(string $field): string
    {
        return ['department' => 'department_id', 'designation' => 'designation_id', 'job_grade' => 'job_grade_id'][$field];
    }

    /** @return mixed|false  false when the value cannot be read as its type. */
    private function cast(mixed $value, array $meta): mixed
    {
        return match ($meta['type'] ?? 'text') {
            'date' => $this->date($value),
            'decimal' => is_numeric(str_replace(',', '', (string) $value))
                ? (float) str_replace(',', '', (string) $value)
                : false,
            'enum' => in_array($v = mb_strtolower(str_replace([' ', '-'], '_', trim((string) $value))), $meta['values'], true)
                ? $v
                : false,
            default => (string) $value,
        };
    }

    /**
     * A date, however the sheet wrote it.
     *
     * Excel hands back a serial number for a real date cell and a string for
     * anything typed as text, and a sheet prepared by three people has both.
     */
    private function date(mixed $value): string|false
    {
        if (is_numeric($value)) {
            try {
                return Carbon::instance(
                    \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)
                )->toDateString();
            } catch (\Throwable) {
                return false;
            }
        }

        $text = trim((string) $value);

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'd M Y', 'j F Y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $text);
            } catch (\Throwable) {
                // Carbon throws on a mismatch rather than returning null, and
                // trying six formats means five of them will throw. A format
                // that does not fit is not an error, it is the next guess.
                continue;
            }

            /*
             * Round-tripped, because Carbon will accept 15/03/2024 as Y-m-d
             * and hand back something in 0015. A date that does not print
             * back as it was written was not read correctly.
             */
            if ($parsed && $parsed->format($format) === $text) {
                return $parsed->toDateString();
            }
        }

        return false;
    }

    private function nextNumber(): string
    {
        $last = Employee::withoutGlobalScope('organization')->withTrashed()
            ->orderByDesc('id')->value('employee_number');

        return 'EMP-' . str_pad((string) (((int) preg_replace('/\D/', '', (string) $last)) + 1), 5, '0', STR_PAD_LEFT);
    }

    public static function mayImport(?User $user, ?int $organizationId): bool
    {
        return (bool) $user
            && $user->hasPermission(Permission::EMPLOYEES_CREATE, $organizationId)
            && $user->hasPermission(Permission::EMPLOYEES_UPDATE, $organizationId);
    }
}
