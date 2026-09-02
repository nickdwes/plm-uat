<?php

namespace App\Models\Procurement;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditTrail;
use App\Models\Project\Project;
use App\Models\Project\ProjectMaterialOrder;
use App\Models\Training\VendorPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The order placed with a supplier. Its status follows what has actually been
 * received rather than being set by hand, so "received" always means the goods
 * are in.
 */
class PurchaseOrder extends Model
{
    use BelongsToOrganization, RecordsAuditTrail;

    protected array $auditExclude = ['updated_at'];

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_ISSUED    = 'issued';
    public const STATUS_ACCEPTED  = 'vendor_accepted';
    public const STATUS_DECLINED  = 'vendor_declined';
    public const STATUS_PARTIAL   = 'partially_received';
    public const STATUS_RECEIVED  = 'received';
    public const STATUS_CLOSED    = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_ISSUED, self::STATUS_ACCEPTED, self::STATUS_DECLINED,
        'in_delivery', self::STATUS_PARTIAL, self::STATUS_RECEIVED, self::STATUS_CLOSED, self::STATUS_CANCELLED,
    ];

    /** Statuses where the order is still live against the budget. */
    public const OPEN_STATUSES = [self::STATUS_ISSUED, self::STATUS_ACCEPTED, 'in_delivery', self::STATUS_PARTIAL];

    protected $fillable = [
        'organization_id', 'procurement_request_id', 'quotation_id', 'vendor_partner_id', 'project_id',
        'reference', 'amount', 'tax_rate', 'tax_amount', 'total_amount', 'currency', 'quantity_ordered',
        'payment_terms', 'delivery_terms', 'expected_delivery_date',
        'status', 'issued_at', 'accepted_at', 'acceptance_note', 'material_order_id', 'created_by',
    ];

    protected $casts = [
        'amount'                 => 'decimal:2',
        'tax_rate'               => 'decimal:2',
        'tax_amount'             => 'decimal:2',
        'total_amount'           => 'decimal:2',
        'quantity_ordered'       => 'decimal:2',
        'expected_delivery_date' => 'date',
        'issued_at'              => 'datetime',
        'accepted_at'            => 'datetime',
    ];

    protected $attributes = ['status' => self::STATUS_DRAFT, 'currency' => 'KES'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ProcurementRequest::class, 'procurement_request_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorPartner::class, 'vendor_partner_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function materialOrder(): BelongsTo
    {
        return $this->belongsTo(ProjectMaterialOrder::class, 'material_order_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceiptNote::class)->orderBy('id');
    }

    /** How much of the order has actually arrived. */
    public function quantityReceived(): float
    {
        return (float) $this->receipts()->sum('quantity_received');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
