<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'azure_id',
        'sso_only',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * All role assignments (may span multiple organizations).
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot('organization_id')
            ->withTimestamps();
    }

    // ── RBAC Helpers ──────────────────────────────────────────────────────────

    /**
     * Check if the user holds a role, optionally scoped to an organization.
     * Passing null for $organizationId checks for group-level (scope = group) roles.
     */
    public function hasRole(string $roleName, ?int $organizationId = null): bool
    {
        return $this->roles()
            ->where('roles.name', $roleName)
            ->where('user_roles.organization_id', $organizationId)
            ->exists();
    }

    public function isGroupAdmin(): bool
    {
        return $this->hasRole(Role::GROUP_ADMIN);
    }

    public function isOrgHR(?int $organizationId = null): bool
    {
        if ($this->isGroupAdmin()) {
            return true;
        }

        return $this->hasRole(Role::ORG_HR, $organizationId);
    }

    public function isManagerIn(?int $organizationId = null): bool
    {
        if ($this->isOrgHR($organizationId)) {
            return true;
        }

        return $this->hasRole(Role::DEPT_MANAGER, $organizationId);
    }

    /**
     * Return all organization IDs this user has any role in.
     */
    public function accessibleOrganizationIds(): array
    {
        if ($this->isGroupAdmin()) {
            return Organization::pluck('id')->toArray();
        }

        return $this->roles()
            ->whereNotNull('user_roles.organization_id')
            ->pluck('user_roles.organization_id')
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }
}
