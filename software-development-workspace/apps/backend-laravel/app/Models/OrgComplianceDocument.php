<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrgComplianceDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'document_key',
        'segment',
        'document_label',
        'is_perpetual',
        'file_url',
        'file_name',
        'file_disk_path',
        'sharepoint_item_id',
        'issue_date',
        'expiry_date',
        'anniversary_date',
        'notes',
        'owner_name',
        'owner_email',
        'status_override',
        'audit_trail',
        'renewal_guide',
        'reminder_days_before',
        'reminder_recipients',
        'reminder_active',
        'reminder_thresholds',
        'last_reminder_sent_at',
    ];

    protected $casts = [
        'is_perpetual'        => 'boolean',
        'reminder_active'     => 'boolean',
        'issue_date'          => 'date:Y-m-d',
        'expiry_date'         => 'date:Y-m-d',
        'anniversary_date'    => 'date:Y-m-d',
        'last_reminder_sent_at' => 'datetime',
        'audit_trail'         => 'array',
        'reminder_thresholds' => 'array',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // ── Status logic ──────────────────────────────────────────────────────────

    /**
     * Returns one of:
     *   "Not Uploaded" | "Under Review" | "Pending Renewal" |
     *   "Valid" | "Expiring (6 months)" | "Expiring (3 months)" |
     *   "Expiring (1 month)" | "Expiring (this week)" | "Expired"
     */
    public function getStatusAttribute(): string
    {
        // Manual override (e.g. "Under Review", "Pending Renewal")
        if ($this->status_override) {
            return $this->status_override;
        }

        if (! $this->file_url) {
            return 'Not Uploaded';
        }

        if ($this->is_perpetual || ! $this->expiry_date) {
            return 'Valid';
        }

        $today  = now()->startOfDay();
        $expiry = $this->expiry_date->copy()->startOfDay();

        if ($today->gt($expiry)) {
            return 'Expired';
        }

        $daysLeft = (int) $today->diffInDays($expiry, false);

        if ($daysLeft <= 7)   return 'Expiring (this week)';
        if ($daysLeft <= 30)  return 'Expiring (1 month)';
        if ($daysLeft <= 90)  return 'Expiring (3 months)';
        if ($daysLeft <= 180) return 'Expiring (6 months)';

        return 'Valid';
    }

    /**
     * Return days until expiry (null if perpetual or no expiry set).
     */
    public function getDaysUntilExpiryAttribute(): ?int
    {
        if ($this->is_perpetual || ! $this->expiry_date) {
            return null;
        }
        $diff = (int) now()->startOfDay()->diffInDays($this->expiry_date->startOfDay(), false);
        return $diff;
    }

    // ── Audit trail ───────────────────────────────────────────────────────────

    /**
     * Append an entry to the audit trail and save.
     */
    public function appendAudit(string $action, string $by, string $note = ''): void
    {
        $trail   = $this->audit_trail ?? [];
        $trail[] = [
            'at'     => now()->toIso8601String(),
            'by'     => $by,
            'action' => $action,
            'note'   => $note,
        ];
        $this->audit_trail = $trail;
        $this->save();
    }

    // ── Reminder thresholds ───────────────────────────────────────────────────

    /**
     * Default reminder thresholds in days before expiry.
     * 180 = 6 months, 90 = 3 months, 30 = 1 month,
     * then 7 weekly checks in last month, 1 daily in last week.
     */
    public static function defaultThresholds(): array
    {
        return [180, 90, 30, 23, 16, 9, 6, 5, 4, 3, 2, 1];
    }
}
