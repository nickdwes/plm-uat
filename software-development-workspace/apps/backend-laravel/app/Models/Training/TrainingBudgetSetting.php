<?php

namespace App\Models\Training;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Same shape as PayrollDocumentSetting: one row per organisation, created
 * on first read with a safe default. Here that default is "no threshold" —
 * absent configuration means every budget request stops at the requester's
 * manager, never silently escalating to Finance for a reason nobody set.
 */
class TrainingBudgetSetting extends Model
{
    protected $fillable = [
        'organization_id',
        'approval_threshold_amount',
        'approval_threshold_currency',
    ];

    protected $casts = [
        'approval_threshold_amount' => 'decimal:2',
    ];

    protected $attributes = [
        'approval_threshold_currency' => 'KES',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function forOrganization(int $organizationId): self
    {
        return static::firstOrCreate(['organization_id' => $organizationId]);
    }
}
