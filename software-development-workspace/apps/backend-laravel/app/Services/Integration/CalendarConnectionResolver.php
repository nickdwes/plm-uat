<?php

namespace App\Services\Integration;

use App\Models\Integration\IntegrationConnection;
use App\Models\Organization;
use RuntimeException;

/**
 * Which connection creates this org's Teams meetings — one explicit answer,
 * shared by every feature that needs it.
 *
 * `organizations.calendar_connection_id` is the single source of truth.
 * Nothing here guesses: an org with three Microsoft connections and no
 * calendar one chosen gets a clear error, not a silent pick of whichever row
 * sorts first.
 */
class CalendarConnectionResolver
{
    public static function resolve(int $organizationId): IntegrationConnection
    {
        $org = Organization::findOrFail($organizationId);

        if (! $org->calendar_connection_id) {
            throw new RuntimeException(
                'No calendar connection is configured for this organisation. Set one under Connected Tenants.',
            );
        }

        $connection = IntegrationConnection::where('organization_id', $organizationId)
            ->where('id', $org->calendar_connection_id)
            ->where('is_active', true)
            ->first();

        if (! $connection) {
            throw new RuntimeException('The configured calendar connection no longer exists or is inactive.');
        }

        return $connection;
    }
}
