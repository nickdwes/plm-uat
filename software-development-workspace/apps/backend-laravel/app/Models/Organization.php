<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'legal_name',
        'industry',
        'country',
        'currency',
        'logo_path',
        'primary_color',
        'primary_email',
        'primary_phone',
        'address',
        'is_active',
        'settings',
    ];

    protected $casts = [
        'settings'  => 'array',
        'is_active' => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'primary_organization_id');
    }

    /**
     * Shared employees linked to this org (not necessarily primary members).
     */
    public function sharedEmployees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_organization_map')
            ->withPivot(['designation_id', 'department_id', 'reports_to', 'access_scope', 'role', 'effective_from', 'effective_to', 'is_active'])
            ->withTimestamps();
    }

    public function policies(): BelongsToMany
    {
        return $this->belongsToMany(SharedPolicy::class, 'organization_policies')
            ->withPivot(['overrides', 'is_active'])
            ->withTimestamps();
    }

    public function jobGrades(): HasMany
    {
        return $this->hasMany(JobGrade::class);
    }

    public function designations(): HasMany
    {
        return $this->hasMany(Designation::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function headcount(): int
    {
        return $this->employees()->where('status', 'active')->count();
    }
}
