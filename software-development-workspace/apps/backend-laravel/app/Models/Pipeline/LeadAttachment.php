<?php

namespace App\Models\Pipeline;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A document filed against a lead.
 *
 * Soft-deleted: taking a requirements document off the screen should not
 * destroy the record that it was once what we were quoting against.
 */
class LeadAttachment extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'lead_attachments';

    protected $fillable = [
        'organization_id', 'lead_id', 'document_type', 'label',
        'original_name', 'storage_path', 'mime_type', 'size_bytes', 'uploaded_by',
    ];

    protected $casts = ['size_bytes' => 'integer'];

    /** Whose document it is, which is what the Documents tab groups by. */
    public const DOCUMENT_TYPES = ['client', 'internal', 'financial', 'technical'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
