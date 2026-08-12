<?php

namespace App\Models\Asset;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photograph of an asset.
 *
 * The condition something was in when it was handed over is what gets argued
 * about when it comes back. `at_handover` marks the ones taken at that moment,
 * because a picture from today does not settle a dispute about last year.
 *
 * Stored through the vault on the asset documents disk — a photograph of a
 * server rack shows the serial numbers of everything in it.
 */
class AssetPhoto extends Model
{
    use BelongsToOrganization;

    protected $table = 'asset_photos';

    protected $fillable = [
        'organization_id', 'asset_id', 'storage_path', 'original_name',
        'mime_type', 'size_bytes', 'caption', 'is_primary', 'at_handover', 'uploaded_by',
    ];

    protected $casts = [
        'is_primary'  => 'boolean',
        'at_handover' => 'boolean',
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
