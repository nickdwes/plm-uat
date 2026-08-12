<?php

namespace App\Models\Asset;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who to tell when a renewal is coming.
 *
 * Per asset, because the person who cares that the Adobe
 * licence is up is not the person who cares about the SSL certificate.
 */
class AssetRenewalWatcher extends Model
{
    protected $table = 'asset_renewal_watchers';

    protected $fillable = ['asset_id', 'employee_id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
