<?php

namespace App\Models\Asset;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One seat of a licence, held by one person.
 *
 * This is what replaced a typed `seats_used`. A number could say eighteen were
 * in use; it could not say which eighteen, which is the only form of the
 * question anybody actually asks — at a renewal, at an exit interview, or when
 * a vendor audits.
 *
 * Released rather than deleted. A seat held for eight months and given back is
 * part of what a renewal conversation needs to know, and removing the row would
 * make a fully-consumed licence look under-used for the year it was full.
 */
class AssetSeatAllocation extends Model
{
    use BelongsToOrganization;

    protected $table = 'asset_seat_allocations';

    protected $fillable = [
        'organization_id', 'asset_id', 'employee_id',
        'allocated_on', 'released_on', 'note', 'allocated_by',
    ];

    protected $casts = [
        'allocated_on' => 'date',
        'released_on'  => 'date',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }

    /** Still consuming a seat. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('released_on');
    }

    public function isActive(): bool
    {
        return $this->released_on === null;
    }
}
