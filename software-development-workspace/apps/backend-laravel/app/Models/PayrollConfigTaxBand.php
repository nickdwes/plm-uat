<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollConfigTaxBand extends Model
{
    use BelongsToOrganization;

    protected $table = 'payroll_config_tax_bands';

    protected $fillable = [
        'organization_id',
        'tax_year',
        'band_order',
        'lower_limit',
        'upper_limit',
        'rate',
        'effective_date',
        'expires_at',
    ];

    protected $casts = [
        'lower_limit'    => 'float',
        'upper_limit'    => 'float',
        'rate'           => 'float',
        'effective_date' => 'date',
        'expires_at'     => 'date',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
