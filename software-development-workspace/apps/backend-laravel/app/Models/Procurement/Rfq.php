<?php

namespace App\Models\Procurement;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditTrail;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request for quotation — the competitive step between approving a purchase
 * and committing to a supplier.
 */
class Rfq extends Model
{
    use BelongsToOrganization, RecordsAuditTrail;

    protected $table = 'rfqs';

    protected array $auditExclude = ['updated_at'];

    /** How many quotations an award needs before the rule is satisfied. */
    public const REQUIRED_QUOTES = 3;

    public const STATUSES = ['draft', 'issued', 'closed', 'cancelled'];

    protected $fillable = [
        'organization_id', 'procurement_request_id', 'reference', 'status',
        'issue_date', 'due_date', 'notes',
        'compliance_met', 'waiver_reason', 'waived_by', 'waived_at', 'created_by',
    ];

    protected $casts = [
        'issue_date'     => 'date',
        'due_date'       => 'date',
        'compliance_met' => 'boolean',
        'waived_at'      => 'datetime',
    ];

    protected $attributes = ['status' => 'draft', 'compliance_met' => false];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ProcurementRequest::class, 'procurement_request_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(RfqVendor::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(ProcurementQuotation::class)->orderBy('amount');
    }

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }

    /** Whether an award may proceed: enough quotes, or a recorded waiver. */
    public function mayAward(): bool
    {
        return $this->quotations()->count() >= self::REQUIRED_QUOTES || filled($this->waiver_reason);
    }
}
