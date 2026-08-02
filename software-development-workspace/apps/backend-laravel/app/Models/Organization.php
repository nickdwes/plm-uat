<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

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

    /**
     * Absolute URL for the org logo, or null when none is set.
     *
     * Logos are written to the `public` disk, so they must be read from it too.
     * Call sites previously used the facade's default disk, which is `local`.
     * That disk configures no `url`, so Laravel fell back to a root-relative
     * `/storage/...` — correct only when the API and the SPA share an origin.
     * They do not, so every logo resolved against the frontend host and 404'd.
     *
     * Resolving it here means the disk is named once rather than at each caller.
     */
    public function logoUrl(): ?string
    {
        return $this->logo_path
            ? Storage::disk('public')->url($this->logo_path)
            : null;
    }

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
