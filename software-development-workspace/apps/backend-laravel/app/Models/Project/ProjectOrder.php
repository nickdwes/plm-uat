<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditTrail;
use App\Models\Training\VendorPartner;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One order-lifecycle record generated from a BOM line, pointing at the module
 * record that fulfils it. The status walks the fulfilment lifecycle; the linked
 * record answers "how far along is it really".
 */
class ProjectOrder extends Model
{
    use BelongsToOrganization, RecordsAuditTrail;

    protected $table = 'project_orders';

    /** Routine churn that would drown the trail — the meaningful change is status. */
    protected array $auditExclude = ['updated_at'];

    public const STATUS_DRAFT           = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED        = 'approved';
    public const STATUS_PO_RELEASED     = 'po_released';
    public const STATUS_ORDERED         = 'ordered';
    public const STATUS_FULFILLED       = 'fulfilled';
    public const STATUS_DELIVERED       = 'delivered';
    public const STATUS_ACCEPTED        = 'accepted';
    public const STATUS_CLOSED          = 'closed';
    public const STATUS_CANCELLED       = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL, self::STATUS_APPROVED,
        self::STATUS_PO_RELEASED, self::STATUS_ORDERED, self::STATUS_FULFILLED,
        self::STATUS_DELIVERED, self::STATUS_ACCEPTED, self::STATUS_CLOSED,
        self::STATUS_CANCELLED,
    ];

    /** The stamp each status sets when it is entered. */
    public const STATUS_STAMP = [
        self::STATUS_ORDERED   => 'ordered_at',
        self::STATUS_DELIVERED => 'delivered_at',
        self::STATUS_ACCEPTED  => 'accepted_at',
        self::STATUS_CLOSED    => 'closed_at',
    ];

    protected $fillable = [
        'organization_id', 'project_id', 'bom_item_id', 'reference',
        'category', 'description', 'part_number', 'vendor_partner_id',
        'quantity', 'unit_cost', 'selling_price', 'order_value', 'margin', 'currency',
        'expected_delivery_date', 'phase_id', 'status',
        'fulfillable_type', 'fulfillable_id',
        'ordered_at', 'delivered_at', 'accepted_at', 'closed_at',
        'accepted_by', 'acceptance_note', 'created_by',
    ];

    protected $casts = [
        'quantity'      => 'decimal:2',
        'unit_cost'     => 'decimal:2',
        'selling_price' => 'decimal:2',
        'order_value'   => 'decimal:2',
        'margin'        => 'decimal:2',
        'expected_delivery_date' => 'date',
        'ordered_at'    => 'datetime',
        'delivered_at'  => 'datetime',
        'accepted_at'   => 'datetime',
        'closed_at'     => 'datetime',
    ];

    protected $attributes = ['status' => self::STATUS_APPROVED, 'currency' => 'KES'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function bomItem(): BelongsTo
    {
        return $this->belongsTo(ProjectBomItem::class, 'bom_item_id');
    }

    public function vendorPartner(): BelongsTo
    {
        return $this->belongsTo(VendorPartner::class, 'vendor_partner_id');
    }

    public function fulfillable(): MorphTo
    {
        return $this->morphTo();
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }
}
