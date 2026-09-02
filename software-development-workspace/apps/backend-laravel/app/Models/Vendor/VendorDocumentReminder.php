<?php

namespace App\Models\Vendor;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rung of the expiry ladder that has already been rung.
 *
 * Exists so the sweep can be idempotent: the unique (document, stage_key) is
 * what stops a run twice in one day — or a run after three quiet days —
 * nagging anybody twice. Same shape, and same reasoning, as
 * `asset_renewal_reminders`.
 */
class VendorDocumentReminder extends Model
{
    protected $fillable = ['vendor_document_id', 'stage_key', 'sent_on', 'days_out'];

    protected $casts = ['sent_on' => 'date', 'days_out' => 'integer'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(VendorDocument::class, 'vendor_document_id');
    }
}
