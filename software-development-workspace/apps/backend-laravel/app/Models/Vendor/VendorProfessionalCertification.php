<?php

namespace App\Models\Vendor;

use App\Models\Training\TrainingItem;
use App\Models\Training\VendorPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VendorProfessionalCertification extends Model
{
    protected $table = 'vendor_professional_certifications';

    protected $fillable = [
        'uuid', 'vendor_partner_id', 'training_item_id',
        'name', 'code', 'level',
        'technology_space', 'technology_subcategory',
        'description', 'validity_months', 'exam_cost_usd', 'training_cost_usd',
        'prerequisites', 'is_active',
    ];

    protected $casts = [
        'is_active'          => 'boolean',
        'validity_months'    => 'integer',
        'exam_cost_usd'      => 'float',
        'training_cost_usd'  => 'float',
        'training_item_id'   => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $m) {
            if (empty($m->uuid)) $m->uuid = (string) Str::uuid();
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorPartner::class, 'vendor_partner_id');
    }

    public function trainingItem(): BelongsTo
    {
        return $this->belongsTo(TrainingItem::class, 'training_item_id');
    }
}
