<?php

namespace App\Models\Procurement;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditTrail;
use App\Models\Training\VendorPartner;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the supplier billed, and whether it agrees with the order and the goods.
 */
class SupplierInvoice extends Model
{
    use BelongsToOrganization, RecordsAuditTrail;

    protected array $auditExclude = ['updated_at'];

    public const STATUS_PENDING   = 'pending';
    public const STATUS_MATCHED   = 'matched';
    public const STATUS_EXCEPTION = 'exception';
    public const STATUS_APPROVED  = 'approved_for_payment';
    public const STATUS_PAID      = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_MATCHED, self::STATUS_EXCEPTION,
        self::STATUS_APPROVED, self::STATUS_PAID, self::STATUS_CANCELLED,
    ];

    /** Invoices that still represent money owed rather than money gone. */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_MATCHED, self::STATUS_EXCEPTION, self::STATUS_APPROVED];

    protected $fillable = [
        'organization_id', 'purchase_order_id', 'vendor_partner_id', 'reference', 'invoice_number',
        'invoice_date', 'due_date', 'amount', 'tax_amount', 'total_amount', 'currency',
        'matched_po', 'matched_grn', 'matched_price', 'match_status', 'exception_reason',
        'exception_overridden_by', 'override_justification',
        'status', 'paid_amount', 'paid_at', 'payment_reference', 'created_by',
    ];

    protected $casts = [
        'invoice_date'  => 'date',
        'due_date'      => 'date',
        'amount'        => 'decimal:2',
        'tax_amount'    => 'decimal:2',
        'total_amount'  => 'decimal:2',
        'paid_amount'   => 'decimal:2',
        'matched_po'    => 'boolean',
        'matched_grn'   => 'boolean',
        'matched_price' => 'boolean',
        'paid_at'       => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING, 'match_status' => 'pending', 'currency' => 'KES', 'paid_amount' => 0,
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorPartner::class, 'vendor_partner_id');
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exception_overridden_by');
    }

    /** What is still owed on this invoice. */
    public function balance(): float
    {
        return round((float) $this->total_amount - (float) $this->paid_amount, 2);
    }

    public function isSettled(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
