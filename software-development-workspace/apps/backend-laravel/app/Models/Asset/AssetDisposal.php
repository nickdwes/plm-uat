<?php

namespace App\Models\Asset;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How something left the register.
 *
 * The Disposals tab could have been a filter over retired assets. This record
 * exists for the two things a filter cannot hold: who authorised it, and
 * whether the item needed a licensed handler.
 *
 * A laptop battery, a monitor and a UPS are electronic waste, which in Kenya is
 * NEMA's business under the Sustainable Waste Management Act. "We gave them to
 * a man with a van" is not a defence, and the register has to be able to
 * produce the certificate.
 */
class AssetDisposal extends Model
{
    use BelongsToOrganization;

    protected $table = 'asset_disposals';

    protected $fillable = [
        'organization_id', 'asset_id', 'method', 'disposed_on',
        'requires_safe_disposal', 'handler_name', 'handler_licence_ref',
        'certificate_received_on', 'proceeds',
        'approved_by', 'approved_at', 'reason', 'notes', 'recorded_by',
    ];

    protected $casts = [
        'disposed_on'             => 'date',
        'certificate_received_on' => 'date',
        'approved_at'             => 'datetime',
        'requires_safe_disposal'  => 'boolean',
        'proceeds'                => 'decimal:2',
    ];

    /** Sold and scrapped are not the same event, and neither is written off. */
    public const METHODS = [
        'sold', 'donated', 'recycled', 'scrapped',
        'returned_to_vendor', 'written_off', 'lost',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Needed a licensed handler and has no certificate on file.
     *
     * The one row in this table an auditor asks about, so it is answered here
     * rather than left to whoever builds the report.
     */
    public function isMissingItsCertificate(): bool
    {
        return $this->requires_safe_disposal && $this->certificate_received_on === null;
    }
}
