<?php

namespace App\Models\Vendor;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Training\VendorPartner;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A piece of paper a supplier has produced.
 *
 * Held on the encrypted `vendor_documents` disk through DocumentVault, like
 * every other document class here — a tax certificate carries a company's
 * registration and turnover, and the register should not be the easiest place
 * to read either.
 */
class VendorDocument extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $fillable = [
        'organization_id', 'vendor_partner_id', 'document_key', 'label',
        'original_name', 'storage_path', 'mime_type', 'size_bytes',
        'issue_date', 'expiry_date', 'is_perpetual', 'notes', 'uploaded_by',
    ];

    protected $casts = [
        'issue_date'   => 'date',
        'expiry_date'  => 'date',
        'is_perpetual' => 'boolean',
        'size_bytes'   => 'integer',
    ];

    public const DISK = 'vendor_documents';

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorPartner::class, 'vendor_partner_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(VendorDocumentReminder::class);
    }

    /**
     * Past its expiry date.
     *
     * A perpetual document is never expired even if somebody typed a date
     * into it — the flag is the stronger statement of the two.
     */
    public function isExpired(): bool
    {
        return ! $this->is_perpetual
            && $this->expiry_date !== null
            && $this->expiry_date->isPast();
    }

    /** Null for perpetual documents and ones with no stated expiry. */
    public function daysToExpiry(): ?int
    {
        if ($this->is_perpetual || $this->expiry_date === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->expiry_date->startOfDay(), false);
    }
}
