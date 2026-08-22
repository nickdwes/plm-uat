<?php

namespace App\Services\HR;

use App\Models\Employee;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Support\Collection;

/**
 * The people report: one catalogue of columns, one query, two consumers.
 *
 * The preview on screen and the spreadsheet come from this same definition, so
 * a column that reads one way in the browser cannot read another way in the
 * file somebody forwards to Finance.
 *
 * ── Sensitivity is decided here, not in the picker ──
 *
 * Pay and statutory identifiers are the reason an export is the easiest way to
 * take a personnel file out of a building. The catalogue omits them for a
 * viewer without the permission, and `resolve()` drops them again on the way
 * out — so a hand-written request naming `basic_salary` gets a spreadsheet
 * without it rather than one with it. Hiding a checkbox is not access control.
 */
class PeopleReport
{
    /**
     * Every column, in the order they are offered.
     *
     * `sensitive` follows the same rule EmployeeResource applies — the
     * permission or an HR role. "Or self" is deliberately absent: exporting
     * everybody is not looking at your own record.
     *
     * @return array<string, array{label: string, group: string, sensitive: bool, numeric?: bool}>
     */
    public static function catalogue(): array
    {
        return [
            'employee_number'   => ['label' => 'Employee No.',       'group' => 'Identity',   'sensitive' => false],
            'full_name'         => ['label' => 'Name',               'group' => 'Identity',   'sensitive' => false],
            'first_name'        => ['label' => 'First Name',         'group' => 'Identity',   'sensitive' => false],
            'last_name'         => ['label' => 'Last Name',          'group' => 'Identity',   'sensitive' => false],
            'work_email'        => ['label' => 'Work Email',         'group' => 'Contact',    'sensitive' => false],
            'phone'             => ['label' => 'Phone',              'group' => 'Contact',    'sensitive' => false],
            'personal_email'    => ['label' => 'Personal Email',     'group' => 'Contact',    'sensitive' => true],
            'city'              => ['label' => 'City',               'group' => 'Contact',    'sensitive' => false],
            'country'           => ['label' => 'Country',            'group' => 'Contact',    'sensitive' => false],

            'department'        => ['label' => 'Department',         'group' => 'Job',        'sensitive' => false],
            'designation'       => ['label' => 'Job Title',          'group' => 'Job',        'sensitive' => false],
            'job_grade'         => ['label' => 'Job Grade',          'group' => 'Job',        'sensitive' => false],
            'employment_type'   => ['label' => 'Employment Type',    'group' => 'Job',        'sensitive' => false],
            'status'            => ['label' => 'Status',             'group' => 'Job',        'sensitive' => false],
            'manager'           => ['label' => 'Reports To',         'group' => 'Job',        'sensitive' => false],
            'home_organization' => ['label' => 'Employed By',        'group' => 'Job',        'sensitive' => false],
            'date_hired'        => ['label' => 'Date Joined',        'group' => 'Job',        'sensitive' => false],
            'probation_end_date'=> ['label' => 'Probation Ends',     'group' => 'Job',        'sensitive' => false],
            'contract_end_date' => ['label' => 'Contract Ends',      'group' => 'Job',        'sensitive' => false],
            'date_exited'       => ['label' => 'Date Exited',        'group' => 'Job',        'sensitive' => false],
            'exit_reason'       => ['label' => 'Exit Reason',        'group' => 'Job',        'sensitive' => true],

            'gender'            => ['label' => 'Gender',             'group' => 'Personal',   'sensitive' => true],
            'date_of_birth'     => ['label' => 'Date of Birth',      'group' => 'Personal',   'sensitive' => true],
            'marital_status'    => ['label' => 'Marital Status',     'group' => 'Personal',   'sensitive' => true],

            'national_id'       => ['label' => 'National ID',        'group' => 'Statutory',  'sensitive' => true],
            'passport_number'   => ['label' => 'Passport No.',       'group' => 'Statutory',  'sensitive' => true],
            'tax_id'            => ['label' => 'KRA PIN',            'group' => 'Statutory',  'sensitive' => true],
            'nssf_number'       => ['label' => 'NSSF Number',        'group' => 'Statutory',  'sensitive' => true],
            'nhif_number'       => ['label' => 'SHA / NHIF Number',  'group' => 'Statutory',  'sensitive' => true],

            'basic_salary'      => ['label' => 'Basic Salary',       'group' => 'Pay', 'sensitive' => true, 'numeric' => true],
            'house_allowance'   => ['label' => 'House Allowance',    'group' => 'Pay', 'sensitive' => true, 'numeric' => true],
            'transport_allowance' => ['label' => 'Transport Allowance', 'group' => 'Pay', 'sensitive' => true, 'numeric' => true],
            'medical_allowance' => ['label' => 'Medical Allowance',  'group' => 'Pay', 'sensitive' => true, 'numeric' => true],
            'other_allowances'  => ['label' => 'Other Allowances',   'group' => 'Pay', 'sensitive' => true, 'numeric' => true],
            'gross_pay'         => ['label' => 'Gross Pay',          'group' => 'Pay', 'sensitive' => true, 'numeric' => true],
            'bank_name'         => ['label' => 'Bank',               'group' => 'Pay', 'sensitive' => true],
            'bank_account_number' => ['label' => 'Bank Account',     'group' => 'Pay', 'sensitive' => true],
        ];
    }

    /** The columns chosen by default — a useful report before anybody touches anything. */
    public const DEFAULTS = [
        'employee_number', 'full_name', 'work_email', 'department',
        'designation', 'employment_type', 'status', 'date_hired',
    ];

    public static function maySeeSensitive(?User $user, ?int $organizationId): bool
    {
        return (bool) $user
            && ($user->hasPermission(Permission::EMPLOYEES_VIEW_SENSITIVE, $organizationId)
                || $user->isOrgHR($organizationId)
                || $user->isGroupAdmin());
    }

    /**
     * The catalogue as this viewer may see it.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function availableTo(?User $user, ?int $organizationId): array
    {
        if (self::maySeeSensitive($user, $organizationId)) {
            return self::catalogue();
        }

        return array_filter(self::catalogue(), fn ($c) => ! $c['sensitive']);
    }

    /**
     * The columns actually used: what was asked for, filtered to what is
     * allowed, in the order it was asked for.
     *
     * Order is the caller's because "configurable columns" means arrangement
     * as well as choice — somebody building a payroll sheet wants the name
     * first and the account number last.
     *
     * @param  array<int, string>  $requested
     * @return array<int, string>
     */
    public static function resolve(array $requested, ?User $user, ?int $organizationId): array
    {
        $allowed = self::availableTo($user, $organizationId);

        $chosen = collect($requested)
            ->map(fn ($c) => trim((string) $c))
            ->filter(fn ($c) => isset($allowed[$c]))
            ->unique()
            ->values()
            ->all();

        return $chosen ?: array_values(array_filter(
            self::DEFAULTS,
            fn ($c) => isset($allowed[$c]),
        ));
    }

    /**
     * The query behind the report — the same population the directory lists.
     *
     * Employed here or placed here, and drafts excluded unless asked for by
     * name, so a headcount taken from this report matches the one on screen.
     */
    public static function query(?int $organizationId, array $filters = [])
    {
        return Employee::query()
            ->when($organizationId, fn ($q, $org) => $q->forOrganization($org))
            ->with(['department:id,name', 'designation:id,title', 'jobGrade:id,name',
                    'manager:id,first_name,last_name', 'primaryOrganization:id,name'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q
                ->where(fn ($w) => $w
                    ->where('first_name', 'ilike', "%{$s}%")
                    ->orWhere('last_name', 'ilike', "%{$s}%")
                    ->orWhere('employee_number', 'ilike', "%{$s}%")
                    ->orWhere('work_email', 'ilike', "%{$s}%")))
            ->when($filters['department_id'] ?? null, fn ($q, $d) => $q->where('department_id', $d))
            ->when($filters['employment_type'] ?? null, fn ($q, $t) => $q->where('employment_type', $t))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->unless($filters['status'] ?? null, fn ($q) => $q->where('status', '!=', 'draft'))
            ->when(
                $filters['on_leave'] ?? null,
                fn ($q) => $q->whereIn('id', DirectoryFigures::onLeaveToday($organizationId)),
            )
            ->orderBy('last_name')
            ->orderBy('first_name');
    }

    /**
     * One employee as the chosen columns, in the chosen order.
     *
     * @param  array<int, string>  $columns
     * @return array<int, mixed>
     */
    public static function row(Employee $e, array $columns): array
    {
        return array_map(fn ($c) => self::value($e, $c), $columns);
    }

    private static function value(Employee $e, string $column): mixed
    {
        return match ($column) {
            'full_name'         => trim("{$e->first_name} {$e->last_name}"),
            'department'        => $e->getAttribute('host_department') ?? $e->department?->name,
            'designation'       => $e->getAttribute('host_title') ?? $e->designation?->title,
            'job_grade'         => $e->jobGrade?->name,
            'manager'           => $e->manager ? trim("{$e->manager->first_name} {$e->manager->last_name}") : null,
            'home_organization' => $e->primaryOrganization?->name,
            // Summed here rather than stored, the same way the profile shows it.
            'gross_pay'         => (float) $e->basic_salary + (float) $e->house_allowance
                                 + (float) $e->transport_allowance + (float) $e->medical_allowance
                                 + (float) $e->other_allowances,
            'date_of_birth', 'date_hired', 'probation_end_date',
            'contract_end_date', 'date_exited' => $e->{$column}?->toDateString(),
            default             => $e->{$column},
        };
    }

    /**
     * @param  array<int, string>  $columns
     * @return Collection<int, string>
     */
    public static function headings(array $columns): Collection
    {
        $catalogue = self::catalogue();

        return collect($columns)->map(fn ($c) => $catalogue[$c]['label'] ?? $c);
    }

    /** Which of the chosen columns hold numbers, so a spreadsheet can sum them. */
    public static function numericColumns(array $columns): array
    {
        $catalogue = self::catalogue();

        return array_values(array_filter($columns, fn ($c) => ! empty($catalogue[$c]['numeric'])));
    }
}
