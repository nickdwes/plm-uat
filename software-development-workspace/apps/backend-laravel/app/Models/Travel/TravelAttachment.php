<?php

namespace App\Models\Travel;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A supporting document on a request.
 *
 * `document_type` exists so a delivery can close against a signed note rather
 * than against any file at all — otherwise "has an attachment" stands in for
 * "was delivered", and a quotation would satisfy it.
 */
class TravelAttachment extends Model
{
    use SoftDeletes;

    public const TYPES = [
        'delivery_note', 'quotation', 'invoice', 'ticket', 'visa', 'receipt', 'other',
    ];

    protected $fillable = [
        'travel_request_id', 'document_type', 'original_name', 'storage_path',
        'mime_type', 'size_bytes', 'uploaded_by',
    ];

    protected $casts = ['size_bytes' => 'integer'];

    protected $attributes = ['document_type' => 'other'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TravelRequest::class, 'travel_request_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
