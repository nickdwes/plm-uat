<?php

namespace App\Models\Support;

use App\Models\Account\Account;
use App\Models\Account\AccountContact;
use App\Models\Account\AccountContract;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One piece of work for the service desk.
 */
class Ticket extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_tickets';

    public const SOURCES = ['email', 'portal', 'phone', 'agent'];

    protected $fillable = [
        'organization_id', 'reference', 'account_id', 'account_contract_id',
        'subject', 'body',
        'requester_name', 'requester_email', 'requester_user_id', 'account_contact_id',
        'ticket_type_id', 'ticket_status_id', 'urgency_id', 'impact_id', 'priority_id',
        'assigned_to', 'support_team_id', 'parent_ticket_id', 'merged_at', 'source',
        'opened_at', 'first_response_at', 'resolved_at', 'closed_at', 'created_by',
    ];

    protected $casts = [
        'opened_at'         => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at'       => 'datetime',
        'closed_at'         => 'datetime',
        'merged_at'         => 'datetime',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function status(): BelongsTo    { return $this->belongsTo(TicketStatus::class, 'ticket_status_id'); }
    public function type(): BelongsTo      { return $this->belongsTo(TicketType::class, 'ticket_type_id'); }
    public function urgency(): BelongsTo   { return $this->belongsTo(TicketUrgency::class, 'urgency_id'); }
    public function impact(): BelongsTo    { return $this->belongsTo(TicketImpact::class, 'impact_id'); }
    public function priority(): BelongsTo  { return $this->belongsTo(TicketPriority::class, 'priority_id'); }
    public function account(): BelongsTo   { return $this->belongsTo(Account::class); }
    public function contract(): BelongsTo  { return $this->belongsTo(AccountContract::class, 'account_contract_id'); }
    public function contact(): BelongsTo   { return $this->belongsTo(AccountContact::class, 'account_contact_id'); }
    public function assignee(): BelongsTo  { return $this->belongsTo(User::class, 'assigned_to'); }
    public function parent(): BelongsTo    { return $this->belongsTo(self::class, 'parent_ticket_id'); }
    public function team(): BelongsTo      { return $this->belongsTo(Team::class, 'support_team_id'); }

    /** The clock, where this ticket has one. */
    public function sla(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TicketSla::class, 'support_ticket_id');
    }

    public function slaEvents(): HasMany
    {
        return $this->hasMany(SlaEvent::class, 'support_ticket_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'support_ticket_id');
    }

    /** What the customer is allowed to see. */
    public function publicMessages(): HasMany
    {
        return $this->messages()->where('visibility', TicketMessage::VISIBILITY_PUBLIC);
    }

    public function worklogs(): HasMany
    {
        return $this->hasMany(TicketWorklog::class, 'support_ticket_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_ticket_id');
    }

    // ── Derived ───────────────────────────────────────────────────────────────

    /**
     * Minutes logged against this ticket.
     *
     * Summed from the worklogs rather than kept on the ticket: a total that is
     * maintained separately from the entries behind it is a total that will one
     * day disagree with them.
     */
    public function minutesLogged(): int
    {
        return (int) $this->worklogs()->sum('minutes');
    }

    public function isOpen(): bool
    {
        return ! ($this->status?->isTerminal() ?? false);
    }

    /**
     * The next reference for an organisation, per year.
     *
     * Sequential and readable, because it is quoted down a phone line. Scoped
     * per organisation so two tenants never collide, and per year so the number
     * stays short enough to say out loud.
     */
    public static function nextReference(int $organizationId, ?int $year = null): string
    {
        $year = $year ?? (int) now()->format('Y');

        $last = static::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('reference', 'like', "SD-{$year}-%")
            ->orderByDesc('id')
            ->value('reference');

        $next = $last ? ((int) substr($last, -5)) + 1 : 1;

        return sprintf('SD-%d-%05d', $year, $next);
    }
}
