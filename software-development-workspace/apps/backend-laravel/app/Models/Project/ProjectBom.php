<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditTrail;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A project's Bill of Materials header. Lines are edited while it is draft;
 * approving it stamps the approver and lets the order engine generate orders.
 */
class ProjectBom extends Model
{
    use BelongsToOrganization, RecordsAuditTrail;

    protected $table = 'project_boms';

    protected array $auditExclude = ['updated_at'];

    public const STATUS_DRAFT      = 'draft';
    public const STATUS_APPROVED   = 'approved';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_APPROVED, self::STATUS_SUPERSEDED];

    protected $fillable = [
        'organization_id', 'project_id', 'version', 'status',
        'approved_at', 'approved_by', 'notes', 'created_by',
    ];

    protected $casts = [
        'version'     => 'integer',
        'approved_at' => 'datetime',
    ];

    protected $attributes = ['status' => self::STATUS_DRAFT, 'version' => 1];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProjectBomItem::class)->orderBy('line_no')->orderBy('id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
