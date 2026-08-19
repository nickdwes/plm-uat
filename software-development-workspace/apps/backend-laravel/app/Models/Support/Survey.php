<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Did we do a good job.
 *
 * Ported from the old module, which collected 360 responses — a real answer
 * rate, and worth keeping the shape of. A rating, three specific judgements and
 * a comment: asking one question gets a number nobody can act on, and asking
 * twelve gets no answer at all.
 */
class Survey extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_surveys';

    public const ANSWERS = ['yes', 'partly', 'no'];

    protected $fillable = [
        'organization_id', 'support_ticket_id', 'token_hash',
        'sent_to', 'sent_at', 'expires_at',
        'rating', 'satisfaction', 'timeliness', 'resolved_fully', 'comment', 'responded_at',
    ];

    protected $casts = [
        'sent_at'      => 'datetime',
        'expires_at'   => 'datetime',
        'responded_at' => 'datetime',
        'rating'       => 'integer',
    ];

    protected $hidden = ['token_hash'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'support_ticket_id');
    }

    /** @return array{token: string, record: self} */
    public static function issue(Ticket $ticket, ?string $email, int $days = 30): array
    {
        $plain = Str::random(48);

        $record = static::updateOrCreate(
            ['support_ticket_id' => $ticket->id],
            [
                'organization_id' => $ticket->organization_id,
                'token_hash'      => hash('sha256', $plain),
                'sent_to'         => $email,
                'sent_at'         => now(),
                'expires_at'      => now()->addDays($days),
            ],
        );

        return ['token' => $plain, 'record' => $record];
    }

    public static function resolve(?string $plain): ?self
    {
        if (blank($plain)) {
            return null;
        }

        $survey = static::withoutGlobalScope('organization')
            ->where('token_hash', hash('sha256', $plain))
            ->first();

        if (! $survey) {
            return null;
        }

        return $survey->expires_at?->isPast() ? null : $survey;
    }

    public function isAnswered(): bool
    {
        return $this->responded_at !== null;
    }
}
