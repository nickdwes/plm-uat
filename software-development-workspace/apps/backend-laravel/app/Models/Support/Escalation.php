<?php

namespace App\Models\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody decided this needs different hands.
 *
 * Not the SLA ladder. That one fires on a clock and says a target is at risk;
 * this is a judgement with a reason attached, and the questions asked about
 * each afterwards are different enough that sharing a table would blur both.
 */
class Escalation extends Model
{
    protected $table = 'support_escalations';

    public const LEVEL_SPECIALIST    = 'specialist';
    public const LEVEL_TEAM_LEAD     = 'team_lead';
    public const LEVEL_BUSINESS_UNIT = 'business_unit';
    public const LEVEL_VENDOR        = 'vendor';
    public const LEVEL_SERVICE_DESK  = 'service_desk';

    public const LEVELS = [
        self::LEVEL_SPECIALIST    => 'Specialist engineer',
        self::LEVEL_TEAM_LEAD     => 'Technical team lead',
        self::LEVEL_BUSINESS_UNIT => 'Business unit',
        self::LEVEL_VENDOR        => 'Vendor or supplier',
        self::LEVEL_SERVICE_DESK  => 'Back to the service desk',
    ];

    protected $fillable = [
        'support_ticket_id', 'level',
        'from_user_id', 'to_user_id', 'from_team_id', 'to_team_id',
        'reason', 'escalated_by', 'escalated_at',
        'resolved_at', 'outcome', 'resolved_by',
    ];

    protected $casts = ['escalated_at' => 'datetime', 'resolved_at' => 'datetime'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'support_ticket_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by');
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }
}
