<?php

namespace App\Services\Account;

use App\Models\Account\Account;
use App\Models\Account\AccountContact;
use App\Support\Account\ReferenceSequence;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AccountService
{
    public function list(array $filters, int $perPage = 20, ?int $limitToManager = null): LengthAwarePaginator
    {
        // primaryContact rather than contacts: the list shows one person per
        // row, and loading every contact of every customer to find them is how
        // a customer list gets slow once the book is real.
        $q = Account::query()->with(['accountManager', 'type', 'sector', 'subsector', 'primaryContact']);

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
            // Allocated here rather than by the caller, so a reference exists
            // from the moment the record does — an import that already has one
            // passes it through and only new records draw from the sequence.
            'reference'       => $data['reference'] ?? ReferenceSequence::next('CL', 'accounts', $organizationId),
            'enrolled_on'     => $data['enrolled_on'] ?? now()->toDateString(),
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
     *
     * Category is what the caller sets; `is_primary` is derived from it. They
     * are kept in one place so a contact cannot be filed as Secondary while
     * still holding the flag that makes the screens call them first.
     */
    public function addContact(Account $account, array $data, ?int $actorId): AccountContact
    {
        return DB::transaction(function () use ($account, $data, $actorId) {
            $first = ! $account->contacts()->exists();

            // The first contact is primary whether or not anyone said so —
            // there is nobody else to call.
            $category = $first ? 'primary' : ($data['category'] ?? 'other');

            if ($category === 'primary' && ! $first) {
                $this->standDownPrimary($account->id);
            }

            // Merged this way round so the derived values win: `+` would keep
            // an is_primary the caller sent and let it contradict the category.
            return $account->contacts()->create(array_merge($data, [
                'category'        => $category,
                'is_primary'      => $category === 'primary',
                'organization_id' => $account->organization_id,
                'created_by'      => $actorId,
            ]));
        });
    }

    public function updateContact(AccountContact $contact, array $data): AccountContact
    {
        return DB::transaction(function () use ($contact, $data) {
            if (array_key_exists('category', $data)) {
                if ($data['category'] === 'primary') {
                    $this->standDownPrimary($contact->account_id, $contact->getKey());
                }

                $data['is_primary'] = $data['category'] === 'primary';
            }

            $contact->update($data);

            // Demoting the primary would otherwise leave nobody to call.
            if (($data['category'] ?? null) !== null && $data['category'] !== 'primary') {
                $this->ensureSomebodyIsPrimary($contact->account_id);
            }

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
                $this->ensureSomebodyIsPrimary($accountId);
            }
        });
    }

    /** Whoever holds primary gives it up, keeping category and flag together. */
    private function standDownPrimary(int $accountId, ?int $exceptId = null): void
    {
        AccountContact::where('account_id', $accountId)
            ->where('is_primary', true)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->update(['is_primary' => false, 'category' => 'secondary']);
    }

    private function ensureSomebodyIsPrimary(int $accountId): void
    {
        if (AccountContact::where('account_id', $accountId)->where('is_primary', true)->exists()) {
            return;
        }

        AccountContact::where('account_id', $accountId)
            ->where('is_active', true)
            ->oldest('id')
            ->first()?->update(['is_primary' => true, 'category' => 'primary']);
    }
}
