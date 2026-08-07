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
            ->withPivot(['designation_id', 'department_id', 'reports_to', 'access_scope', 'role', 'allocation_pct', 'is_billable', 'effective_from', 'effective_to', 'is_active', 'notes'])
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

    public function secondments(): HasMany
    {
        return $this->hasMany(EmployeeSecondment::class)->orderByDesc('start_date');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Employees belonging to a specific organization (primary or shared).
     * Used by group-level queries that bypass the global scope.
     */
    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where(function ($q) use ($organizationId) {
            $q->where('primary_organization_id', $organizationId)
              ->orWhereHas('organizations', fn ($r) =>
                  $r->where('organization_id', $organizationId)
                    ->where('employee_organization_map.is_active', true)
              );
        });
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
