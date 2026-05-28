<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OkrObjective extends Model
{
    protected $table = 'okr_objectives';

    protected $fillable = [
        'cycle_id', 'organization_id', 'title', 'description', 'weight', 'sort_order',
    ];

    protected $casts = [
        'weight'     => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(OkrCycle::class, 'cycle_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function keyResults(): HasMany
    {
        return $this->hasMany(OkrKeyResult::class, 'objective_id')->orderBy('sort_order');
    }
}
