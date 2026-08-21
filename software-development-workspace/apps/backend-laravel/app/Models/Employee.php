<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\EmployeeEmergencyContact;
use App\Models\EmployeeDependent;
use App\Models\EmployeeVehicle;

/**
 * Employee is NOT using BelongsToOrganization because its tenancy column is
 * primary_organization_id, not organization_id. Scoping is handled explicitly
 * via the ResolveOrganizationContext middleware + query builder conditions in
 * the EmployeeRepository.
 *
 * Cross-org access for shared employees is controlled via
 * employee_organization_map.
 */
class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        // Where pay actually goes. Left out of $fillable these are silently
        // dropped on save and the payment file quietly excludes everybody.
        'payment_method', 'bank_name', 'bank_code', 'branch_code',
        'bank_account_number', 'bank_account_name', 'mobile_money_number',
        'employee_number',
        'user_id',
        'primary_organization_id',
        'department_id',
        'designation_id',
        'job_grade_id',
        'reports_to',
        'first_name',
        'last_name',
        'middle_name',
        'preferred_name',
        'date_of_birth',
        'gender',
        'marital_status',
        'national_id',
        'passport_number',
        'tax_id',
        'nssf_number',
        'nhif_number',
        'personal_email',
        'work_email',
        'phone',
        'address',
        'city',
        'county',
        'country',
        'employment_type',
        'status',
        'date_hired',
        'probation_end_date',
        'contract_end_date',
        'date_exited',
        'exit_reason',
        'is_shared',
        // Extended personal / health
        'linkedin_profile',
        'residential_address',
        'blood_group',
        'allergies',
        'known_conditions',
        'disabilities',
        'basic_salary',
        'house_allowance',
        'transport_allowance',
        'medical_allowance',
        'other_allowances',
        'insurance_relief',
        // Benefits
        'medical_plan',
        'pension_provider',
        'benefit_medical',
        'benefit_life_insurance',
        'benefit_pension',
        'benefit_wiba',
        'benefit_group_life',
        'benefits_effective_date',
        // Compliance
        'nssf_registered',
        'nhif_registered',
        'kra_registered',
        'has_work_permit',
        'work_permit_number',
        'work_permit_expiry',
        // Profile / career
        'photo_url',
        'professional_summary',
        'about_me',
        'hobbies_interests',
        'job_description',
    ];

    protected $casts = [
        'date_of_birth'       => 'date',
        'date_hired'          => 'date',
        'probation_end_date'  => 'date',
        'contract_end_date'   => 'date',
        'date_exited'         => 'date',
        'is_shared'           => 'boolean',
        'basic_salary'        => 'decimal:2',
        'house_allowance'     => 'decimal:2',
        'transport_allowance' => 'decimal:2',
        'medical_allowance'   => 'decimal:2',
        'other_allowances'    => 'decimal:2',
        'insurance_relief'    => 'decimal:2',
        'benefits_effective_date' => 'date',
        'benefit_medical'        => 'boolean',
        'benefit_life_insurance' => 'boolean',
        'benefit_pension'        => 'boolean',
        'benefit_wiba'           => 'boolean',
        'benefit_group_life'     => 'boolean',
        'nssf_registered'        => 'boolean',
        'nhif_registered'        => 'boolean',
        'kra_registered'         => 'boolean',
        'has_work_permit'        => 'boolean',
        'work_permit_expiry'     => 'date',
    ];

    // ── Computed ──────────────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->preferred_name ?? $this->full_name;
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function primaryOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'primary_organization_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function jobGrade(): BelongsTo
    {
        return $this->belongsTo(JobGrade::class);
    }

    /**
     * Direct manager within primary organization.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reports_to');
    }

    /**
     * Direct reports within primary organization.
     */
    public function directReports(): HasMany
    {
        return $this->hasMany(Employee::class, 'reports_to');
    }

    /**
     * All organizations this employee is linked to (primary + shared).
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'employee_organization_map')
            ->withPivot([
                'designation_id', 'department_id', 'reports_to', 'access_scope',
                'role', 'engagement_type', 'allocation_pct', 'is_billable',
                'effective_from', 'effective_to', 'is_active', 'notes',
            ])
            ->withTimestamps();
    }

    public function reportingLines(): HasMany
    {
        return $this->hasMany(ReportingLine::class);
    }

    public function managedReportingLines(): HasMany
    {
        return $this->hasMany(ReportingLine::class, 'manager_id');
    }

    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmployeeEmergencyContact::class);
    }

    public function dependents(): HasMany
    {
        return $this->hasMany(EmployeeDependent::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(EmployeeVehicle::class);
    }

    /** Where they worked, and whether the day happened. */
    public function attendanceDays(): HasMany
    {
        return $this->hasMany(\App\Models\Attendance\AttendanceDay::class);
    }

    public function onboardingInductions(): HasMany
    {
        return $this->hasMany(OnboardingInduction::class);
    }

    public function onboardingDocumentChecklist(): HasMany
    {
        return $this->hasMany(OnboardingDocumentChecklist::class);
    }

    public function onboardingAssets(): HasMany
    {
        return $this->hasMany(OnboardingAsset::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(EmployeeQualification::class)->orderByDesc('year_obtained');
    }

    /**
     * Every organisation besides their primary one.
     *
     * Secondments live on the organisation map rather than a table of their
     * own — the map already carries the allocation, whether it is billable and
     * who they report to there, none of which a free-text organisation name
     * could answer.
     */
    public function secondments()
    {
        return $this->organizations()
            ->wherePivot('organization_id', '!=', $this->primary_organization_id);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Everybody who works for an organisation — employed by it, or placed in it.
     *
     * The two are different facts and both are membership. Somebody seconded
     * into an organisation at eighty per cent of their week works there in
     * every sense the people around them care about; that they are on another
     * company's payroll is a fact about their contract, not about whether they
     * belong on the directory.
     *
     * The date window matters as much as the flag. A placement that ended in
     * March is not membership in June, and checking `is_active` alone leaves a
     * lapsed secondee in the directory until somebody remembers the row.
     */
    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where(function ($q) use ($organizationId) {
            $q->where('primary_organization_id', $organizationId)
              ->orWhereHas('organizations', fn ($r) => $r
                  ->where('organization_id', $organizationId)
                  ->where('employee_organization_map.is_active', true)
                  ->where(fn ($w) => $w
                      ->whereNull('employee_organization_map.effective_to')
                      ->orWhereDate('employee_organization_map.effective_to', '>=', now()->toDateString()))
                  ->whereDate('employee_organization_map.effective_from', '<=', now()->toDateString()));
        });
    }

    /**
     * Overlay the job each visitor does *here*.
     *
     * A person's department, title and manager are all per-organisation, and
     * their own record holds their home organisation's. Read here they are
     * worse than wrong: `Department` and `Designation` are tenant-scoped, so a
     * host organisation loading a visitor's relations gets nulls, and the
     * directory shows a blank where the job should be.
     *
     * One query and an overlay rather than asking each person for their
     * context, which is a query apiece — a listing that costs a round trip per
     * head is a listing that times out on the organisation big enough to need
     * one.
     *
     * @param  \Illuminate\Support\Collection<int, self>  $people
     */
    public static function attachPlacements($people, ?int $organizationId): void
    {
        if ($organizationId === null) {
            return;
        }

        $visiting = $people->filter(fn (self $e) => $e->primary_organization_id !== $organizationId);

        if ($visiting->isEmpty()) {
            return;
        }

        $placements = \Illuminate\Support\Facades\DB::table('employee_organization_map as m')
            ->leftJoin('designations as d', 'd.id', '=', 'm.designation_id')
            ->leftJoin('departments as p', 'p.id', '=', 'm.department_id')
            ->where('m.organization_id', $organizationId)
            ->where('m.is_active', true)
            ->whereIn('m.employee_id', $visiting->pluck('id'))
            ->get(['m.employee_id', 'm.reports_to', 'm.role', 'm.allocation_pct',
                   'd.title as designation', 'p.name as department'])
            ->keyBy('employee_id');

        foreach ($visiting as $employee) {
            $placement = $placements[$employee->id] ?? null;

            if (! $placement) {
                continue;
            }

            /*
             * Their manager here, which may be nobody — a secondee often
             * answers to their home manager on paper. Anyone whose manager is
             * not on this chart is promoted to a root, so an unattached
             * placement lands sensibly rather than disappearing.
             */
            $employee->reports_to = $placement->reports_to;

            $employee->setAttribute('host_title', $placement->designation ?: $placement->role);
            $employee->setAttribute('host_department', $placement->department);
            $employee->setAttribute('host_allocation_pct', $placement->allocation_pct);
        }
    }

    /**
     * Placed here rather than employed here.
     *
     * Read by the directory so a visiting colleague can be labelled as one.
     * Nobody should have to work out whose payroll somebody is on by noticing
     * that their department looks unfamiliar.
     */
    public function isVisiting(?int $organizationId): bool
    {
        return $organizationId !== null
            && $this->primary_organization_id !== $organizationId;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePrimary($query, int $organizationId)
    {
        return $query->where('primary_organization_id', $organizationId);
    }

    public function scopeShared($query)
    {
        return $query->where('is_shared', true);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Resolve this employee's context (department, manager, role) within a
     * given organization. Returns primary-org values if the org is primary,
     * otherwise returns the mapping row data.
     */
    public function contextForOrganization(int $organizationId): array
    {
        if ($this->primary_organization_id === $organizationId) {
            return [
                'organization_id' => $organizationId,
                'department_id'   => $this->department_id,
                'designation_id'  => $this->designation_id,
                'reports_to'      => $this->reports_to,
                'access_scope'    => 'full',
                'role'            => $this->designation?->title,
                'is_primary'      => true,
            ];
        }

        $map = $this->organizations()
            ->wherePivot('organization_id', $organizationId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $map) {
            return [];
        }

        return [
            'organization_id' => $organizationId,
            'department_id'   => $map->pivot->department_id,
            'designation_id'  => $map->pivot->designation_id,
            'reports_to'      => $map->pivot->reports_to,
            'access_scope'    => $map->pivot->access_scope,
            'role'            => $map->pivot->role,
            'is_primary'      => false,
        ];
    }
}
