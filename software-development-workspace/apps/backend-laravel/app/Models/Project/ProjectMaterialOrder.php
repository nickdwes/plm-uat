<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Training\VendorPartner;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A material order a project raises against a supplier — the head record the
 * tracker, delivery updates, logistics contacts and documents hang off.
 */
class ProjectMaterialOrder extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'project_material_orders';

    public const STATUS_PENDING    = 'pending';
    public const STATUS_ORDERED    = 'ordered';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_DELIVERED  = 'delivered';
    public const STATUS_CANCELLED  = 'cancelled';
    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_ORDERED, self::STATUS_IN_TRANSIT,
        self::STATUS_DELIVERED, self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'organization_id', 'project_id', 'reference', 'name', 'description',
        'status', 'due_date', 'vendor_partner_id', 'supplier_name', 'lpo_reference', 'created_by',
    ];

    protected $casts = ['due_date' => 'date'];

    protected $attributes = ['status' => self::STATUS_PENDING];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(VendorPartner::class, 'vendor_partner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function trackers(): HasMany
    {
        return $this->hasMany(ProjectMaterialOrderTracker::class, 'material_order_id')->orderByDesc('happened_on')->orderByDesc('id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(ProjectMaterialOrderUpdate::class, 'material_order_id')->latest('id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ProjectMaterialOrderContact::class, 'material_order_id')->orderBy('name');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProjectAttachment::class, 'material_order_id')->latest('id');
    }

    /** Past its due date and not yet delivered or cancelled. */
    public function getIsOverdueAttribute(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && ! in_array($this->status, [self::STATUS_DELIVERED, self::STATUS_CANCELLED], true);
    }
}
