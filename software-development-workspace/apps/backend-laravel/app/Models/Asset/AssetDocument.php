<?php

namespace App\Models\Asset;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paperwork attached to an asset.
 *
 * Typed, because the request is almost always for one kind — the signed
 * agreement, the invoice — and a flat list makes somebody open six files to
 * find it.
 */
class AssetDocument extends Model
{
    use BelongsToOrganization;

    protected $table = 'asset_documents';

    protected $fillable = [
        'organization_id', 'asset_id', 'category', 'storage_path',
        'original_name', 'mime_type', 'size_bytes', 'note', 'uploaded_by',
    ];

    /** From the brief, plus the two every asset ends up needing. */
    public const CATEGORIES = [
        'licence_agreement', 'purchase_invoice', 'vendor_contract',
        'architecture_diagram', 'installation_guide', 'user_guide',
        'security_assessment', 'support_document', 'warranty', 'other',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
