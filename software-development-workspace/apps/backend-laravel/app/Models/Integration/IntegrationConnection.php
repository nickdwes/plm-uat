<?php

namespace App\Models\Integration;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant the register is allowed to read.
 */
class IntegrationConnection extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    public const PROVIDER_MICROSOFT = 'microsoft';

    public const STATUS_OK     = 'ok';
    public const STATUS_FAILED = 'failed';

    /**
     * The telemetry columns are here too, and they have to be.
     *
     * Left out, `update(['last_test_status' => ...])` is silently dropped by
     * mass-assignment protection and every test result vanishes — the screen
     * then shows "never tested" immediately after a successful test. Nothing
     * user-supplied reaches them: the controller validates a fixed whitelist
     * that does not include any of these.
     */
    protected $fillable = [
        'organization_id', 'name', 'provider',
        'tenant_id', 'client_id', 'client_secret', 'secret_expires_on',
        'sync_licences', 'sync_azure', 'is_active', 'created_by',
        'last_tested_at', 'last_test_status', 'last_test_message',
        'granted_roles', 'last_synced_at',
    ];

    protected $casts = [
        /*
         * Encrypted at rest with the application key, and cast rather than
         * hand-encrypted so nothing can write the column raw by accident.
         */
        'client_secret'     => 'encrypted',
        'secret_expires_on' => 'date',
        'sync_licences'     => 'boolean',
        'sync_azure'        => 'boolean',
        'is_active'         => 'boolean',
        'last_tested_at'    => 'datetime',
        'last_synced_at'    => 'datetime',
        'granted_roles'     => 'array',
    ];

    /**
     * Never serialised, in either direction.
     *
     * `$hidden` stops it reaching a JSON response even if a resource forgets to
     * exclude it. The screen that sets a secret has no business reading one
     * back — that turns a single XSS into a stolen tenant credential.
     */
    protected $hidden = ['client_secret'];

    /**
     * Column defaults do not reach the model instance after create().
     *
     * Without these a freshly created connection reads `sync_licences` as null
     * even though the row in the database says true, and every guard written as
     * `abort_unless($connection->sync_licences, ...)` refuses a connection that
     * is in fact configured. The same note appears on Asset and AssetRequest.
     */
    protected $attributes = [
        'provider'      => self::PROVIDER_MICROSOFT,
        'sync_licences' => true,
        'sync_azure'    => false,
        'is_active'     => true,
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(IntegrationSubscription::class)->orderBy('display_name');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Derived ───────────────────────────────────────────────────────────────

    public function hasSecret(): bool
    {
        return filled($this->getRawOriginal('client_secret'));
    }

    /**
     * Days until the credential stops working — negative once it has.
     *
     * Derived rather than stored, for the same reason every other expiry in
     * this codebase is: a stored count is wrong the day after it is written.
     */
    public function daysToSecretExpiry(): ?int
    {
        if ($this->secret_expires_on === null) {
            return null;
        }

        return (int) \Carbon\CarbonImmutable::now()
            ->startOfDay()
            ->diffInDays($this->secret_expires_on->startOfDay(), false);
    }

    /**
     * Worth warning about before it breaks.
     *
     * A month, because rotating a client secret means a change in Entra and a
     * deploy of the new value, and a fortnight's notice has a way of becoming
     * a Friday afternoon.
     */
    public function secretExpiringSoon(): bool
    {
        $days = $this->daysToSecretExpiry();

        return $days !== null && $days <= 30;
    }

    /** Subscriptions somebody has actually ticked. */
    public function enabledSubscriptionIds(): array
    {
        return $this->subscriptions()
            ->where('is_enabled', true)
            ->pluck('subscription_id')
            ->all();
    }
}
