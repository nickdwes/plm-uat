<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SharedPolicy extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'rules',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'rules'     => 'array',
        'is_active' => 'boolean',
    ];

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_policies')
            ->withPivot(['overrides', 'is_active'])
            ->withTimestamps();
    }
}
