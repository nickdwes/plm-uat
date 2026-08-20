<?php

namespace App\Models\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One firing, in words.
 *
 * "Why did this ticket close itself?" cannot be answered from a rule
 * definition — the rule may have changed since, and the ticket may have matched
 * for a reason nobody would guess. A run log costs a row per firing and is the
 * difference between automation people trust and automation they turn off.
 */
class AutomationRun extends Model
{
    protected $table = 'support_automation_runs';

    protected $fillable = [
        'support_automation_id', 'support_ticket_id',
        'was_dry_run', 'summary', 'actions', 'fired_at',
    ];

    protected $casts = [
        'was_dry_run' => 'boolean',
        'actions'     => 'array',
        'fired_at'    => 'datetime',
    ];

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class, 'support_automation_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'support_ticket_id');
    }
}
