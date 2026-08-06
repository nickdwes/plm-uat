<?php

namespace App\Models\Account;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A document filed against a customer, or against one of their contracts.
 *
 * Soft-deleted: removing a signed contract from the screen should not destroy
 * the evidence that it was signed.
 */
class AccountAttachment extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'account_attachments';

    protected $fillable = [
        'organization_id', 'account_id', 'account_contract_id',
        'document_type', 'label', 'original_name', 'storage_path',
        'mime_type', 'size_bytes', 'expiry_date', 'uploaded_by',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'size_bytes'  => 'integer',
    ];

    public const DOCUMENT_TYPES = [
        'contract', 'award_letter', 'purchase_order', 'certificate',
        'registration', 'tax_document', 'other',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(AccountContract::class, 'account_contract_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Documents belonging to the customer rather than to a contract. */
    public function scopeAtAccountLevel(Builder $q): Builder
    {
        return $q->whereNull('account_contract_id');
    }

    public function scopeLapsingWithin(Builder $q, int $days): Builder
    {
        return $q->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }
}
