<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\StoreAccountRequest;
use App\Http\Requests\Account\StoreContactRequest;
use App\Http\Requests\Account\StoreContractRequest;
use App\Http\Resources\Account\AccountActivityResource;
use App\Http\Resources\Account\AccountAttachmentResource;
use App\Http\Resources\Account\AccountContactResource;
use App\Http\Resources\Account\AccountContractResource;
use App\Http\Resources\Account\AccountResource;
use App\Models\Account\Account;
use App\Models\Account\AccountActivity;
use App\Models\Account\AccountAttachment;
use App\Models\Account\AccountContact;
use App\Models\Account\AccountContract;
use App\Models\Account\AccountSector;
use App\Models\Account\AccountSubsector;
use App\Models\Account\AccountTeamMember;
use App\Models\Account\AccountType;
use App\Services\Account\AccountActivityService;
use App\Services\Account\AccountService;
use App\Support\Account\ReferenceSequence;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    private const DETAIL = [
        'accountManager', 'type', 'sector', 'subsector', 'contacts',
        'team.employee', 'contracts.attachments', 'attachments',
    ];

    public function __construct(
        private readonly AccountService $service,
        private readonly AccountActivityService $activities,
    ) {}

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


    /**
     * Trips taken for this customer.
     *
     * The link has existed since Travel was built, but only ever read from the
     * trip's side — so "what has this journey cost us on this account" had no
     * answer without going through every request.
     */
    public function travels(Account $account): JsonResponse
    {
        $this->authorize('view', $account);

        $trips = $account->travelRequests()
            ->with(['employee.designation', 'destination'])
            ->latest('departure_date')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $trips->map(fn ($t) => [
                'id'             => $t->id,
                'reference'      => $t->reference,
                'traveller'      => $t->employee?->full_name,
                'role'           => $t->employee?->designation?->title,
                'destination'    => $t->destination?->name,
                'departure_date' => $t->departure_date?->toDateString(),
                'return_date'    => $t->return_date?->toDateString(),
                'status'         => $t->status,
                'travel_type'    => $t->travel_type,
            ]),
        ]);
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

    // ── Activities ────────────────────────────────────────────────────────
    //
    // The timeline, and the only thing that moves `last_contact_at`.

    public function activities(Account $account): AnonymousResourceCollection
    {
        $this->authorize('view', $account);

        return AccountActivityResource::collection(
            $account->activities()->with(['employee', 'author'])->limit(100)->get()
        );
    }

    public function storeActivity(Request $request, Account $account): AccountActivityResource
    {
        // Logging a call is not editing the customer: anybody who deals with
        // them should be able to say that they spoke to them.
        $this->authorize('view', $account);

        $data = $request->validate([
            'type'        => ['sometimes', Rule::in(AccountActivity::TYPES)],
            'body'        => ['required', 'string', 'max:5000'],
            // Accepted so a call can be logged the following morning and still
            // land on the day it happened.
            'occurred_at' => ['sometimes', 'date', 'before_or_equal:now'],
        ]);

        $activity = $this->activities->log(
            $account, $data, $request->user()->id, $request->user()->employeeId(),
        );

        return new AccountActivityResource($activity->load(['employee', 'author']));
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
                'reference' => ReferenceSequence::next('CT', 'account_contracts', $account->organization_id),
                // Taken from the account, not from the request context: a
                // contract must belong to whoever owns the customer, even if
                // the two could somehow disagree.
                'organization_id' => $account->organization_id,
                'created_by'      => $request->user()->id,
            ]
        ));
    }

    /**
     * Mark a contract done.
     *
     * Its own action rather than an edit of the status field: finishing a
     * contract stamps when it finished, and a plain edit would let the two
     * disagree.
     */
    public function completeContract(Request $request, Account $account, AccountContract $contract): AccountContractResource
    {
        $this->authorize('update', $account);
        abort_unless($contract->account_id === $account->id, 404);

        $contract->update(['status' => 'completed', 'completed_at' => now()]);

        return new AccountContractResource($contract->fresh('attachments'));
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

    // ── Attachments ───────────────────────────────────────────────────────
    //
    // Filed against the customer, or against one of their contracts. Never
    // publicly addressable: a signed contract carries commercial terms and
    // signatures, which is the same reason candidate CVs stopped being.

    public function storeAttachment(Request $request, Account $account): AccountAttachmentResource
    {
        $this->authorize('update', $account);

        $data = $request->validate([
            'file'          => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
            'document_type' => ['sometimes', Rule::in(AccountAttachment::DOCUMENT_TYPES)],
            'label'         => ['nullable', 'string', 'max:255'],
            'expiry_date'   => ['nullable', 'date'],

            // Scoped to this customer's own contracts, so a document cannot be
            // filed against somebody else's agreement.
            'account_contract_id' => ['nullable', 'integer',
                Rule::exists('account_contracts', 'id')->where('account_id', $account->id)],
        ]);

        $file = $request->file('file');
        $path = $file->store("accounts/{$account->id}", 'account_documents');

        $attachment = $account->attachments()->create([
            'organization_id'     => $account->organization_id,
            'account_contract_id' => $data['account_contract_id'] ?? null,
            'document_type'       => $data['document_type'] ?? 'other',
            'label'               => $data['label'] ?? null,
            'original_name'       => $file->getClientOriginalName(),
            'storage_path'        => $path,
            'mime_type'           => $file->getClientMimeType(),
            'size_bytes'          => $file->getSize(),
            'expiry_date'         => $data['expiry_date'] ?? null,
            'uploaded_by'         => $request->user()->id,
        ]);

        return new AccountAttachmentResource($attachment);
    }

    public function downloadAttachment(Account $account, AccountAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $account);
        abort_unless($attachment->account_id === $account->id, 404);

        return Storage::disk('account_documents')->download(
            $attachment->storage_path, $attachment->original_name,
        );
    }

    public function destroyAttachment(Account $account, AccountAttachment $attachment): JsonResponse
    {
        $this->authorize('update', $account);
        abort_unless($attachment->account_id === $account->id, 404);

        // Soft-deleted, and the file is left alone. Taking a contract off the
        // screen should not destroy the evidence that it was signed.
        $attachment->delete();

        return response()->json(['message' => 'Document removed.']);
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
