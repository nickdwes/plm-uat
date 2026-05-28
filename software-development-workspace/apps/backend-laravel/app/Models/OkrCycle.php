<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OkrCycle extends Model
{
    protected $table = 'okr_cycles';

    protected $fillable = [
        'organization_id', 'name', 'year', 'period', 'status',
    ];

    protected $casts = [
        'year' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function objectives(): HasMany
    {
        return $this->hasMany(OkrObjective::class, 'cycle_id')->orderBy('sort_order');
    }
}
