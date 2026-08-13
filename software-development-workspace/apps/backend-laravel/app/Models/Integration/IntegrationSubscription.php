<?php

namespace App\Models\Integration;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Azure subscription under a tenant.
 *
 * Discovered rather than typed. A subscription id is a UUID nobody remembers
 * and everybody mistypes, and the principal can already be asked what it is
 * allowed to see — so the screen lists what is reachable and somebody ticks the
 * ones that belong in this register.
 *
 * Not every reachable subscription should be imported: a principal granted
 * Reader high up the management group tree can see other parts of the business,
 * and importing those is how one company's asset list quietly acquires
 * another's servers. Hence `is_enabled` defaulting to false.
 */
class IntegrationSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'integration_connection_id', 'subscription_id',
        'display_name', 'state', 'is_enabled',
    ];

    protected $casts = [
        'is_enabled'     => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }
}
