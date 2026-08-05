<?php

namespace App\Services\Account;

use App\Models\Account\Account;
use App\Models\Account\AccountContact;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AccountService
{
    public function list(array $filters, int $perPage = 20, ?int $limitToManager = null): LengthAwarePaginator
    {
        $q = Account::query()->with(['accountManager', 'type', 'sector', 'subsector']);

        // Applied before any filter so a caller restricted to their own book
        // cannot widen it with a query string.
        if ($limitToManager !== null) {
            $q->managedBy($limitToManager);
        }

        if ($term = trim((string) ($filters['search'] ?? ''))) {
            $like = '%' . $term . '%';
            $q->where(fn ($w) => $w->where('name', 'ilike', $like)
                ->orWhere('tax_pin', 'ilike', $like)
                ->orWhere('physical_location', 'ilike', $like));
        }

        foreach (['status' => 'status', 'account_type_id' => 'account_type_id',
                  'account_sector_id' => 'account_sector_id', 'country' => 'country',
                  'account_manager_id' => 'account_manager_id'] as $key => $column) {
            if (! empty($filters[$key])) {
                $q->where($column, $filters[$key]);
            }
        }

        return $q->orderBy('name')->paginate($perPage);
    }

    public function create(array $data, int $organizationId, ?int $actorId): Account
    {
        return Account::create($data + [
            'organization_id' => $organizationId,
            'created_by'      => $actorId,
        ]);
    }

    public function update(Account $account, array $data): Account
    {
        $account->update($data);
        return $account->fresh(['accountManager', 'type', 'sector', 'subsector']);
    }

    /**
     * Add a contact, keeping "primary" meaning exactly one person.
     *
     * Enforced in a transaction rather than by a unique index: a partial index
     * would stop two primaries but still permit none, and the useful guarantee
     * is that there is always someone to call.
     */
    public function addContact(Account $account, array $data, ?int $actorId): AccountContact
    {
        return DB::transaction(function () use ($account, $data, $actorId) {
            $first = ! $account->contacts()->exists();

            // The first contact is primary whether or not anyone said so.
            $data['is_primary'] = $first ? true : (bool) ($data['is_primary'] ?? false);

            if ($data['is_primary'] && ! $first) {
                $account->contacts()->update(['is_primary' => false]);
            }

            return $account->contacts()->create($data + [
                'organization_id' => $account->organization_id,
                'created_by'      => $actorId,
            ]);
        });
    }

    public function updateContact(AccountContact $contact, array $data): AccountContact
    {
        return DB::transaction(function () use ($contact, $data) {
            if (! empty($data['is_primary'])) {
                AccountContact::where('account_id', $contact->account_id)
                    ->whereKeyNot($contact->getKey())
                    ->update(['is_primary' => false]);
            }

            $contact->update($data);
            return $contact->fresh();
        });
    }

    /**
     * Remove a contact, promoting a replacement if the primary is deleted.
     *
     * Leaving an account with contacts but no primary is the state that makes
     * "who do we call" unanswerable, and nothing surfaces it.
     */
    public function deleteContact(AccountContact $contact): void
    {
        DB::transaction(function () use ($contact) {
            $wasPrimary = $contact->is_primary;
            $accountId  = $contact->account_id;
            $contact->delete();

            if ($wasPrimary) {
                AccountContact::where('account_id', $accountId)
                    ->where('is_active', true)
                    ->oldest('id')
                    ->first()?->update(['is_primary' => true]);
            }
        });
    }
}
