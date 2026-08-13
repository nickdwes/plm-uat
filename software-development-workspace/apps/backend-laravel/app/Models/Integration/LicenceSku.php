<?php

namespace App\Models\Integration;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** One subscribed SKU, as the tenant last reported it. */
class LicenceSku extends Model
{
    use BelongsToOrganization;

    protected $table = 'integration_licence_skus';

    protected $fillable = [
        'organization_id', 'integration_connection_id', 'sku_id', 'part_number',
        'name', 'classification', 'seats_total', 'consumed_reported',
        'suspended_units', 'capability_status', 'last_seen_at', 'is_present',
    ];

    protected $casts = [
        'seats_total'       => 'integer',
        'consumed_reported' => 'integer',
        'suspended_units'   => 'integer',
        'last_seen_at'      => 'datetime',
        'is_present'        => 'boolean',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(
            DirectoryAccount::class,
            'integration_account_licences',
            'integration_licence_sku_id',
            'integration_directory_account_id',
        );
    }

    /** Counted in utilisation and spend. See LicenceSkus for why free ones are not. */
    public function isPaid(): bool
    {
        return $this->classification === \App\Services\Integration\LicenceSkus::PAID;
    }

    /**
     * Seats paid for and not handed out.
     *
     * Null for anything not paid — a free SKU with 999,976 unused nominal seats
     * is not a saving waiting to be made.
     */
    public function seatsSpare(): ?int
    {
        return $this->isPaid() ? max(0, $this->seats_total - $this->consumed_reported) : null;
    }

    /** Nothing left to give the next person who needs one. */
    public function isFull(): bool
    {
        return $this->isPaid() && $this->seats_total > 0 && $this->seatsSpare() === 0;
    }
}
