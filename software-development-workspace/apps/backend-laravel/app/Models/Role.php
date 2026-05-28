<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'scope',
        'permissions',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    // Role name constants — use these everywhere instead of raw strings
    const GROUP_ADMIN  = 'group_admin';
    const ORG_HR       = 'org_hr';
    const DEPT_MANAGER = 'dept_manager';
    const EMPLOYEE     = 'employee';

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot('organization_id')
            ->withTimestamps();
    }
}
