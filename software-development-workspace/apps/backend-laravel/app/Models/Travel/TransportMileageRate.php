<?php

namespace App\Models\Travel;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Rate per kilometre for self-drive reimbursement.
 *
 * Effective-dated, so a trip approved in March still reconciles against
 * March's rate when somebody checks it in November.
 */
class TransportMileageRate extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'rate_per_km', 'currency', 'effective_from', 'effective_to'];

    protected $casts = [
        'rate_per_km'    => 'decimal:2',
        'effective_from' => 'date',
        'effective_to'   => 'date',
    ];

    protected $attributes = ['currency' => 'KES'];
}
