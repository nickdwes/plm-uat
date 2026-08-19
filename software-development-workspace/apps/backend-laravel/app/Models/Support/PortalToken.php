<?php

namespace App\Models\Support;

use App\Models\Account\AccountContact;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A link that lets a customer see their own correspondence.
 *
 * Stored hashed, so the database never holds anything that opens a ticket —
 * the plaintext exists once, in the email that carried it. Revocable, which a
 * signed URL is not: a link to somebody's correspondence emailed to the wrong
 * address has to be withdrawable, not merely left to expire.
 */
class PortalToken extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_portal_tokens';

    protected $fillable = [
        'organization_id', 'support_ticket_id', 'account_contact_id',
        'token_hash', 'email', 'expires_at', 'last_used_at', 'use_count', 'revoked_at',
    ];

    protected $casts = [
        'expires_at'   => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at'   => 'datetime',
        'use_count'    => 'integer',
    ];

    /** Never serialised. Nothing that opens a ticket belongs in a response. */
    protected $hidden = ['token_hash'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'support_ticket_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(AccountContact::class, 'account_contact_id');
    }

    /**
     * Mint one, returning the plaintext exactly once.
     *
     * @return array{token: string, record: self}
     */
    public static function issue(array $attributes, int $days = 30): array
    {
        $plain = Str::random(48);

        $record = static::create($attributes + [
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays($days),
        ]);

        return ['token' => $plain, 'record' => $record];
    }

    /**
     * Find a live token by its plaintext.
     *
     * Looked up by hash rather than compared row by row: a token table scanned
     * with a string comparison is a timing oracle, and this one is reachable
     * without authenticating.
     */
    public static function resolve(?string $plain): ?self
    {
        if (blank($plain)) {
            return null;
        }

        $token = static::withoutGlobalScope('organization')
            ->where('token_hash', hash('sha256', $plain))
            ->first();

        return $token?->isLive() ? $token : null;
    }

    public function isLive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function touchUse(): void
    {
        $this->forceFill([
            'last_used_at' => now(),
            'use_count'    => $this->use_count + 1,
        ])->save();
    }
}
