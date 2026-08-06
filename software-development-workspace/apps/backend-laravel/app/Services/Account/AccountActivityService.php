<?php

namespace App\Services\Account;

use App\Models\Account\Account;
use App\Models\Account\AccountActivity;
use Illuminate\Support\Facades\DB;

/**
 * The customer's timeline, and the one field derived from it.
 *
 * `accounts.last_contact_at` is denormalised — the list sorts on it, and a
 * per-row subquery over activities is what makes a customer list slow once the
 * book is real. Keeping the write in one place is the price of that: if
 * anything else inserts an activity directly, the column silently stops
 * meaning what the column heading says.
 */
class AccountActivityService
{
    /**
     * Something a person did — a call, a meeting, a note.
     *
     * Only this moves `last_contact_at`. A contract expiring is not us having
     * spoken to anybody, and a list that says otherwise would quietly excuse
     * the customers nobody has called.
     */
    public function log(Account $account, array $data, ?int $actorId, ?int $employeeId = null): AccountActivity
    {
        return DB::transaction(function () use ($account, $data, $actorId, $employeeId) {
            $activity = $account->activities()->create([
                'organization_id' => $account->organization_id,
                'kind'            => 'manual',
                'type'            => $data['type'] ?? 'note',
                'body'            => $data['body'] ?? null,
                'occurred_at'     => $data['occurred_at'] ?? now(),
                'employee_id'     => $employeeId,
                'created_by'      => $actorId,
            ]);

            // Only ever forwards. Logging a call from last month should not
            // make a customer look more recently contacted than they are.
            if ($account->last_contact_at === null || $activity->occurred_at->gt($account->last_contact_at)) {
                $account->forceFill(['last_contact_at' => $activity->occurred_at])->save();
            }

            return $activity;
        });
    }

    /**
     * Something the system observed.
     *
     * Recorded so the timeline is complete, but deliberately not treated as
     * contact — see log() above.
     */
    public function record(Account $account, string $type, string $body, ?int $actorId = null): AccountActivity
    {
        return $account->activities()->create([
            'organization_id' => $account->organization_id,
            'kind'            => 'system',
            'type'            => $type,
            'body'            => $body,
            'occurred_at'     => now(),
            'created_by'      => $actorId,
        ]);
    }
}
