<?php

namespace App\Models\Integration;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One account in the directory.
 *
 * Not an employee, and deliberately not stored as one — see the migration. It
 * exists so the licence questions that need no employee record can be answered
 * today, and so the match has something to write into once employees are
 * migrated.
 */
class DirectoryAccount extends Model
{
    use BelongsToOrganization;

    protected $table = 'integration_directory_accounts';

    protected $fillable = [
        'organization_id', 'integration_connection_id', 'external_id',
        'display_name', 'user_principal_name', 'mail', 'account_enabled',
        'licence_count', 'employee_id', 'last_seen_at', 'is_present',
    ];

    protected $casts = [
        'account_enabled' => 'boolean',
        'licence_count'   => 'integer',
        'last_seen_at'    => 'datetime',
        'is_present'      => 'boolean',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function skus(): BelongsToMany
    {
        return $this->belongsToMany(
            LicenceSku::class,
            'integration_account_licences',
            'integration_directory_account_id',
            'integration_licence_sku_id',
        );
    }

    /** The address to match an employee on, once there are employees to match. */
    public function address(): ?string
    {
        return $this->mail ?: $this->user_principal_name;
    }

    /**
     * Paying for something nobody can use.
     *
     * True regardless of whether an employee has been matched, which is the
     * point: this is the one finding that does not wait on the register being
     * populated.
     */
    public function isPaidForNothing(): bool
    {
        return ! $this->account_enabled && $this->licence_count > 0;
    }
}
