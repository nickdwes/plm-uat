<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Integration\IntegrationConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A mailbox the desk watches. */
class Mailbox extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_mailboxes';

    protected $fillable = [
        'organization_id', 'integration_connection_id', 'address', 'display_name',
        'is_active', 'auto_acknowledge', 'default_type_id',
        'subscription_id', 'subscription_expires_at', 'client_state',
        'last_delivered_at', 'last_polled_at', 'last_error',
    ];

    protected $casts = [
        'is_active'               => 'boolean',
        'auto_acknowledge'        => 'boolean',
        'subscription_expires_at' => 'datetime',
        'last_delivered_at'       => 'datetime',
        'last_polled_at'          => 'datetime',
        /*
         * The secret Graph echoes on every notification. Encrypted and hidden
         * for the same reason as any other shared secret: anything that can
         * authenticate a caller is worth stealing.
         */
        'client_state'            => 'encrypted',
    ];

    protected $attributes = ['is_active' => true, 'auto_acknowledge' => true];

    protected $hidden = ['client_state'];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    public function defaultType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class, 'default_type_id');
    }

    /** Graph subscriptions are short-lived; renew well before the edge. */
    public function needsRenewal(): bool
    {
        return $this->subscription_id === null
            || $this->subscription_expires_at === null
            || $this->subscription_expires_at->lt(now()->addHours(12));
    }
}
