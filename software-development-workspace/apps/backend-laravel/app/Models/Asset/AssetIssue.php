<?php

namespace App\Models\Asset;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetIssue extends Model
{
    use BelongsToOrganization, HasFactory;

    public const STATUS_REPORTED    = 'reported';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED    = 'resolved';

    public const STATUSES = [
        self::STATUS_REPORTED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_RESOLVED,
    ];

    protected $fillable = [
        'organization_id', 'ticket_number', 'asset_id',
        'reported_by', 'assigned_to',
        'issue_type', 'priority', 'description', 'status',
        'resolution', 'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    /** See the note on AssetRequest — a column default does not reach the
     *  model instance after create(). */
    protected $attributes = [
        'status'   => self::STATUS_REPORTED,
        'priority' => 'medium',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** Who raised the fault. */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reported_by');
    }

    /** Who is fixing it — IT, facilities. Distinct from the reporter. */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AssetIssueMessage::class);
    }

    public function isOpen(): bool
    {
        return $this->status !== self::STATUS_RESOLVED;
    }
}
