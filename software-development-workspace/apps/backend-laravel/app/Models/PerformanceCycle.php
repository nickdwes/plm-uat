<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named review period with dates, so every review in it covers the same days.
 */
class PerformanceCycle extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'name', 'starts_on', 'ends_on', 'is_open'];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on'   => 'date',
        'is_open'   => 'boolean',
    ];

    public function reviews(): HasMany
    {
        return $this->hasMany(PerformanceReview::class, 'cycle_id');
    }

    /** How many days the cycle spans, inclusive of both ends. */
    public function getLengthInDaysAttribute(): int
    {
        return $this->starts_on->diffInDays($this->ends_on) + 1;
    }

    /**
     * The window, clipped so it never runs past today.
     *
     * A cycle ending in December measured in August covers eight months, not
     * twelve — counting the days that have not happened yet as anything would
     * make everybody look absent for the rest of the year.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function windowAsAt(?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::now())->startOfDay();

        return [
            $this->starts_on->copy(),
            $this->ends_on->copy()->min($today),
        ];
    }
}
