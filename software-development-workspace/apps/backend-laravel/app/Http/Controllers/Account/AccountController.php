<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\StoreAccountRequest;
use App\Http\Requests\Account\StoreContactRequest;
use App\Http\Resources\Account\AccountContactResource;
use App\Http\Resources\Account\AccountResource;
use App\Models\Account\Account;
use App\Models\Account\AccountContact;
use App\Services\Account\AccountService;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The customer master.
 *
 * Gated on the permission catalogue from the first commit rather than on role
 * names, so this does not join the controllers that have to be migrated later.
 */
class AccountController extends Controller
{
    public function __construct(private readonly AccountService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Account::class);

        // Someone holding only `view_own` sees their own book. Resolved here
        // from the authenticated user rather than from a request parameter,
        // which the caller controls.
        $limit = $request->user()->hasPermission(Permission::ACCOUNTS_VIEW)
            ? null
            : $request->user()->employeeId();

        return AccountResource::collection($this->service->list(
            filters: $request->only(['search', 'status', 'account_type_id', 'account_sector_id', 'country', 'account_manager_id']),
            perPage: (int) $request->input('per_page', 20),
            limitToManager: $limit,
        ));
    }

    public function store(StoreAccountRequest $request): AccountResource
    {
        $this->authorize('create', Account::class);

        $account = $this->service->create(
            data: $request->validated(),
            organizationId: app('current.organization_id'),
            actorId: $request->user()->id,
        );

        return new AccountResource($account->load(['accountManager', 'type', 'sector', 'subsector']));
    }

    public function show(Account $account): AccountResource
    {
        $this->authorize('view', $account);

        return new AccountResource(
            $account->load(['accountManager', 'type', 'sector', 'subsector', 'contacts'])
        );
    }

    public function update(StoreAccountRequest $request, Account $account): AccountResource
    {
        $this->authorize('update', $account);

        $data = $request->validated();

        // Reassignment is a separate authority: moving a customer between
        // people changes visibility and commission, which editing a phone
        // number does not. Silently dropped rather than refused, so an edit
        // that happens to include the current value still succeeds.
        if (array_key_exists('account_manager_id', $data)
            && (int) $data['account_manager_id'] !== (int) $account->account_manager_id
            && ! $request->user()->can('assign', $account)) {
            unset($data['account_manager_id']);
        }

        return new AccountResource($this->service->update($account, $data));
    }

    public function destroy(Account $account): JsonResponse
    {
        $this->authorize('delete', $account);
        $account->delete();

        return response()->json(['message' => 'Customer archived.']);
    }

    // ── Contacts ──────────────────────────────────────────────────────────

    public function storeContact(StoreContactRequest $request, Account $account): AccountContactResource
    {
        $this->authorize('update', $account);

        return new AccountContactResource(
            $this->service->addContact($account, $request->validated(), $request->user()->id)
        );
    }

    public function updateContact(StoreContactRequest $request, Account $account, AccountContact $contact): AccountContactResource
    {
        $this->authorize('update', $account);
        abort_unless($contact->account_id === $account->id, 404);

        return new AccountContactResource($this->service->updateContact($contact, $request->validated()));
    }

    public function destroyContact(Account $account, AccountContact $contact): JsonResponse
    {
        $this->authorize('update', $account);
        abort_unless($contact->account_id === $account->id, 404);

        $this->service->deleteContact($contact);

        return response()->json(['message' => 'Contact removed.']);
    }
}
