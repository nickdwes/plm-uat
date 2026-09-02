<?php

namespace App\Models\Procurement;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditTrail;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What arrived, in what condition, and whether it was on time — the receipt an
 * invoice is later matched against.
 */
class GoodsReceiptNote extends Model
{
    use BelongsToOrganization, RecordsAuditTrail;

    protected array $auditExclude = ['updated_at'];

    public const QUALITY = ['accepted', 'partial', 'rejected'];

    protected $fillable = [
        'organization_id', 'purchase_order_id', 'reference',
        'quantity_ordered', 'quantity_received', 'variance',
        'quality_status', 'quality_notes', 'on_time', 'received_by', 'received_at',
    ];

    protected $casts = [
        'quantity_ordered' => 'decimal:2',
        'quantity_received' => 'decimal:2',
        'variance'         => 'decimal:2',
        'on_time'          => 'boolean',
        'received_at'      => 'datetime',
    ];

    protected $attributes = ['quality_status' => 'accepted'];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
