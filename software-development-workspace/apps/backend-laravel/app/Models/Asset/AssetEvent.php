<?php

namespace App\Models\Asset;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use App\Models\Training\VendorPartner;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened to an asset.
 *
 * The register says where a laptop is now. This says where it has been — who
 * held it in March, how long it sat in the repair queue, how many hands it went
 * through before it was retired. A status column can answer the first question
 * and can never answer any of the others.
 *
 * Written as history: an event is a record of something that occurred on a
 * date, so events are added rather than edited. Correcting one means recording
 * what actually happened, not quietly rewriting what was said before.
 */
class AssetEvent extends Model
{
    use BelongsToOrganization;

    protected $table = 'asset_events';

    protected $fillable = [
        'organization_id', 'asset_id', 'event', 'occurred_on',
        'employee_id', 'location', 'vendor_partner_id', 'note', 'recorded_by',
    ];

    protected $casts = ['occurred_on' => 'date'];

    /**
     * The stages in the designs' lifecycle timeline, in the order they are
     * drawn.
     *
     * `reassigned` is deliberately its own event rather than a second
     * `assigned`. Passing a laptop from one person to another is the moment
     * something can go wrong — nobody signed for it, the condition was never
     * checked — and a timeline that shows two identical "assigned" markers
     * hides exactly the handover worth looking at.
     */
    public const PROCURED    = 'procured';
    public const IN_STOCK    = 'in_stock';
    public const ASSIGNED    = 'assigned';
    public const REASSIGNED  = 'reassigned';
    public const RETURNED    = 'returned';
    public const UNDER_REPAIR = 'under_repair';
    public const REPAIRED    = 'repaired';
    public const LOST        = 'lost';
    public const RETIRED     = 'retired';
    public const DISPOSED    = 'disposed';

    /*
     * Custodianship, kept apart from assignment.
     *
     * A switch changing hands is not a laptop changing hands: nothing is
     * carried away, nothing is signed, and nothing is returned at an exit
     * interview. Recording it as `assigned` would put racked equipment on
     * somebody's leaver checklist.
     */
    public const CUSTODIAN_SET     = 'custodian_set';
    public const CUSTODIAN_CHANGED = 'custodian_changed';
    public const CUSTODIAN_CLEARED = 'custodian_cleared';

    public const EVENTS = [
        self::PROCURED, self::IN_STOCK, self::ASSIGNED, self::REASSIGNED,
        self::RETURNED, self::UNDER_REPAIR, self::REPAIRED, self::LOST,
        self::RETIRED, self::DISPOSED,
        self::CUSTODIAN_SET, self::CUSTODIAN_CHANGED, self::CUSTODIAN_CLEARED,
    ];

    /**
     * Which event puts the asset in which status.
     *
     * Held here rather than left to each caller, so recording that something
     * went for repair cannot leave the register saying it is still on somebody's
     * desk. Events not listed do not move the status — `repaired` returns it to
     * whoever it belonged to, which the caller knows and this does not.
     */
    public const STATUS_AFTER = [
        self::IN_STOCK     => Asset::STATUS_AVAILABLE,
        self::ASSIGNED     => Asset::STATUS_ASSIGNED,
        self::REASSIGNED   => Asset::STATUS_ASSIGNED,
        self::RETURNED     => Asset::STATUS_AVAILABLE,
        self::UNDER_REPAIR => Asset::STATUS_MAINTENANCE,
        self::RETIRED      => Asset::STATUS_RETIRED,
        self::DISPOSED     => Asset::STATUS_RETIRED,
        self::LOST         => Asset::STATUS_RETIRED,

        /*
         * In service, not assigned. `available` on a running virtual machine
         * says it is on a shelf and could be handed to somebody, which is a lie
         * with consequences.
         *
         * Clearing a custodian deliberately moves nothing: a switch with nobody
         * answerable for it is still running, and pretending otherwise would
         * take it out of the register the moment somebody left.
         */
        self::CUSTODIAN_SET     => Asset::STATUS_IN_SERVICE,
        self::CUSTODIAN_CHANGED => Asset::STATUS_IN_SERVICE,
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** Who held it at this point, which is not necessarily who holds it now. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorPartner::class, 'vendor_partner_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
