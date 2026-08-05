<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\StoreAccountRequest;
use App\Http\Requests\Account\StoreContactRequest;
use App\Http\Resources\Account\AccountContactResource;
use App\Http\Requests\Account\StoreContractRequest;
use App\Http\Resources\Account\AccountContractResource;
use App\Http\Resources\Account\AccountResource;
use App\Models\Account\Account;
use App\Models\Account\AccountContact;
use App\Models\Account\AccountContract;
use App\Models\Account\AccountSector;
use App\Models\Account\AccountSubsector;
use App\Models\Account\AccountTeamMember;
use App\Models\Account\AccountType;
use App\Services\Account\AccountService;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The customer master.
 *
 * Gated on the permission catalogue from the first commit rather than on role
 * names, so this does not join the controllers that have to be migrated later.
 */
class AccountController extends Controller
{
    /**
     * Everything the record screen shows. Named once so a relation added to
     * the resource cannot be loaded by three endpoints and missed by the
     * fourth, which surfaces as a tab that is empty only after an edit.
     */
    private const DETAIL = ['accountManager', 'type', 'sector', 'subsector', 'contacts', 'team.employee', 'contracts'];

    public function __construct(private readonly AccountService $service) {}

    /**
     * The lists the record screen needs to render its selects.
     *
     * Gated on seeing customers rather than on managing reference data: the
     * form that reads this is used by everyone who edits an account, and
     * gating it on the admin permission would leave them with empty dropdowns
     * and no error to explain them — the same failure the travel wizard had.
     */
    public function lookups(): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        return response()->json([
            'types'   => AccountType::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'sectors' => AccountSector::where('is_active', true)->orderBy('name')->get(['id', 'name']),

            // Sent whole rather than fetched per sector: the list is small,
            // and one request means changing sector re-filters instantly
            // instead of blanking the field while a fetch is in flight.
            'subsectors' => AccountSubsector::where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'account_sector_id']),
        ]);
    }

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
            $account->load(self::DETAIL)
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


    // ── Team ──────────────────────────────────────────────────────────────
    //
    // Who works the account, as distinct from who owns it. An owner field
    // alone cannot answer "who deals with this client".

    public function storeTeamMember(Request $request, Account $account): AccountResource
    {
        // Adding somebody to a team is a change to the account, so it needs
        // the same authority as editing one.
        $this->authorize('update', $account);

        $data = $request->validate([
            'employee_id' => ['required', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $account->organization_id)],
            'role' => ['nullable', 'string', 'max:120'],
        ]);

        AccountTeamMember::updateOrCreate(
            ['account_id' => $account->id, 'employee_id' => $data['employee_id']],
            [
                'organization_id' => $account->organization_id,
                'role'            => $data['role'] ?? null,
                'added_by'        => $request->user()->id,
            ],
        );

        return new AccountResource($account->fresh(self::DETAIL));
    }

    public function destroyTeamMember(Account $account, AccountTeamMember $member): AccountResource
    {
        $this->authorize('update', $account);
        abort_unless($member->account_id === $account->id, 404);

        $member->delete();

        return new AccountResource($account->fresh(self::DETAIL));
    }

    // ── Contracts ─────────────────────────────────────────────────────────
    //
    // Ported from Plmv2. Gated on editing the customer rather than on a
    // permission of their own: the people who keep a customer record straight
    // are the people who know when its support agreement runs out.

    public function storeContract(StoreContractRequest $request, Account $account): AccountContractResource
    {
        $this->authorize('update', $account);

        return new AccountContractResource($account->contracts()->create(
            $request->validated() + [
                // Taken from the account, not from the request context: a
                // contract must belong to whoever owns the customer, even if
                // the two could somehow disagree.
                'organization_id' => $account->organization_id,
                'created_by'      => $request->user()->id,
            ]
        ));
    }

    public function updateContract(StoreContractRequest $request, Account $account, AccountContract $contract): AccountContractResource
    {
        $this->authorize('update', $account);
        abort_unless($contract->account_id === $account->id, 404);

        $contract->update($request->validated());

        return new AccountContractResource($contract->fresh());
    }

    public function destroyContract(Account $account, AccountContract $contract): JsonResponse
    {
        $this->authorize('update', $account);
        abort_unless($contract->account_id === $account->id, 404);

        $contract->delete();

        return response()->json(['message' => 'Contract removed.']);
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
