<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Resources\Vendor\VendorResource;
use App\Models\Training\VendorPartner;
use App\Models\Vendor\StakeholderRole;
use App\Models\Vendor\VendorContact;
use App\Services\Vendor\DuplicateProbe;
use App\Services\Vendor\RoleFieldValidator;
use App\Services\Vendor\VendorOnboarding;
use App\Support\Authorization\Permission;
use RuntimeException;
use App\Services\Vendor\VendorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VendorController extends Controller
{
    public function __construct(
        private readonly VendorService $service,
        private readonly DuplicateProbe $duplicates,
        private readonly VendorOnboarding $onboarding,
        private readonly RoleFieldValidator $roleFields,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // CRUD
    // ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', VendorPartner::class);

        $vendors = $this->service->list(
            orgId:   app('current.organization_id'),
            filters: $request->only([
                'search', 'onboarding_status', 'is_active',
                // Classification: `role` (and `party_class`) are the current
                // filters; vendor_type / is_oem_vendor are the deprecated
                // pair, kept working for callers that still send them.
                'role', 'party_class', 'vendor_type', 'is_oem_vendor',
            ]),
            perPage: (int) $request->input('per_page', 25),
        );

        return VendorResource::collection($vendors);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', VendorPartner::class);

        $data = $request->validate([
            'name'                        => 'required|string|max:255',
            'registration_number'         => 'nullable|string|max:100',
            'tax_id'                      => 'nullable|string|max:50',
            // A party may play several roles at once — that is the point of
            // the roles table. `vendor_type` stays accepted for older callers.
            'roles'                       => 'required|array|min:1',
            'roles.*'                     => 'string|in:' . implode(',', StakeholderRole::ROLES),
            'party_class'                 => 'sometimes|in:organization,individual',
            'role_details'                => 'sometimes|array',
            'vendor_type'                 => 'sometimes|in:supplier,service_provider,contractor,consultant,distributor,partner',
            'is_oem_vendor'               => 'boolean',
            'category'                    => 'nullable|string|max:100',
            'description'                 => 'nullable|string|max:3000',
            'primary_contact_name'        => 'nullable|string|max:255',
            'primary_contact_designation' => 'nullable|string|max:255',
            'contact_email'               => 'nullable|email|max:255',
            'contact_phone'               => 'nullable|string|max:50',
            'website_url'                 => 'nullable|url|max:500',
            'physical_address'            => 'nullable|string|max:500',
            'city'                        => 'nullable|string|max:100',
            'country'                     => 'nullable|string|max:100',
            'notes'                       => 'nullable|string|max:2000',
            'is_active'                   => 'boolean',
            // Partnership & fiscal
            'partnership_level'           => 'nullable|string|max:50',
            'logo_url'                    => 'nullable|url|max:500',
            'fiscal_year_start_month'     => 'nullable|integer|min:1|max:12',
            'renewal_anniversary_date'    => 'nullable|date',
            'renewal_cost'                => 'nullable|numeric|min:0',
            'renewal_cost_currency'       => 'nullable|string|max:10',
            'renewal_reminder_days'       => 'nullable|integer|min:1|max:365',
            // Remittance & terms — how this supplier actually gets paid.
            'bank_name'                   => 'nullable|string|max:160',
            'bank_branch'                 => 'nullable|string|max:160',
            'account_name'                => 'nullable|string|max:160',
            'account_number'              => 'nullable|string|max:64',
            'swift_code'                  => 'nullable|string|max:32',
            'payment_terms_days'          => 'nullable|integer|min:0|max:365',
            'credit_limit'                => 'nullable|numeric|min:0',
            'credit_limit_currency'       => 'nullable|string|size:3',
            // Registering anyway, having seen the candidates. The reason is
            // mandatory because "they really are two firms" is a claim
            // somebody should have to make in writing.
            'create_anyway'               => 'sometimes|boolean',
            'duplicate_reason'            => 'required_if:create_anyway,true|nullable|string|max:255',
        ]);

        $this->assertRolesSuitClass($data['roles'], $data['party_class'] ?? 'organization');

        $orgId      = app('current.organization_id');
        $overriding = (bool) ($data['create_anyway'] ?? false);
        $reason     = $data['duplicate_reason'] ?? null;
        unset($data['create_anyway'], $data['duplicate_reason']);

        /*
         * A duplicate supplier splits orders, invoices and delivery history
         * across two rows — and the performance scorecard is computed from
         * exactly those. 409 rather than 422: nothing about the payload is
         * invalid, it is the register that already knows this party.
         */
        if (! $overriding) {
            $candidates = $this->duplicates->candidatesFor($orgId, $data);

            if ($candidates !== []) {
                return response()->json([
                    'message'    => 'This party may already be registered.',
                    'error'      => 'possible_duplicate',
                    'candidates' => $candidates,
                ], 409);
            }
        }

        $data['role_details'] = $this->roleFields->check(
            $orgId, $data['roles'], $data['party_class'] ?? 'organization',
            $data['role_details'] ?? [],
        );

        $data['created_by'] = $request->user()->id;

        $vendor = $this->service->create($orgId, $data);

        if ($overriding) {
            // Overriding the guard is a decision, and the register should be
            // able to show who made it and why.
            \App\Services\Audit\AuditLogger::record(
                action: 'duplicate_override',
                auditable: $vendor,
                description: "Registered \"{$vendor->name}\" despite possible duplicates — {$reason}",
                organizationId: $orgId,
            );
        }

        // 201, not the 200 this endpoint used to answer: a caller cannot tell
        // "created" from "already existed" otherwise, and the duplicate guard
        // arriving next increment makes that distinction load-bearing.
        return (new VendorResource($vendor))->response()->setStatusCode(201);
    }

    public function show(VendorPartner $vendor): VendorResource
    {
        $this->authorize('view', $vendor);
        // Approvals included: the chain is part of what a vendor IS on the
        // detail screen, not something that appears only after somebody
        // presses a lifecycle button.
        return new VendorResource($vendor->load(['stakeholderRoles', 'documents', 'approvals']));
    }

    public function update(Request $request, VendorPartner $vendor): VendorResource
    {
        $this->authorize('update', $vendor);

        $data = $request->validate([
            'name'                        => 'sometimes|string|max:255',
            'registration_number'         => 'nullable|string|max:100',
            'tax_id'                      => 'nullable|string|max:50',
            'vendor_type'                 => 'sometimes|in:supplier,service_provider,contractor,consultant,distributor,partner',
            'role_details'                => 'sometimes|array',
            'is_oem_vendor'               => 'boolean',
            'category'                    => 'nullable|string|max:100',
            'description'                 => 'nullable|string|max:3000',
            'primary_contact_name'        => 'nullable|string|max:255',
            'primary_contact_designation' => 'nullable|string|max:255',
            'contact_email'               => 'nullable|email|max:255',
            'contact_phone'               => 'nullable|string|max:50',
            'website_url'                 => 'nullable|url|max:500',
            'physical_address'            => 'nullable|string|max:500',
            'city'                        => 'nullable|string|max:100',
            'country'                     => 'nullable|string|max:100',
            'notes'                       => 'nullable|string|max:2000',
            'is_active'                   => 'boolean',
            // Partnership & fiscal
            'partnership_level'           => 'nullable|string|max:50',
            'logo_url'                    => 'nullable|url|max:500',
            'fiscal_year_start_month'     => 'nullable|integer|min:1|max:12',
            'renewal_anniversary_date'    => 'nullable|date',
            'renewal_cost'                => 'nullable|numeric|min:0',
            'renewal_cost_currency'       => 'nullable|string|max:10',
            'renewal_reminder_days'       => 'nullable|integer|min:1|max:365',
            // Remittance & terms — how this supplier actually gets paid.
            'bank_name'                   => 'nullable|string|max:160',
            'bank_branch'                 => 'nullable|string|max:160',
            'account_name'                => 'nullable|string|max:160',
            'account_number'              => 'nullable|string|max:64',
            'swift_code'                  => 'nullable|string|max:32',
            'payment_terms_days'          => 'nullable|integer|min:0|max:365',
            'credit_limit'                => 'nullable|numeric|min:0',
            'credit_limit_currency'       => 'nullable|string|size:3',
        ]);

        /*
         * On an approved vendor, banking and legal identity do not change on
         * one person's say-so — they queue for confirmation. Everything else
         * in the same submission saves normally, so correcting a phone number
         * is not held hostage to a bank review.
         */
        if (array_key_exists('role_details', $data)) {
            $vendor->loadMissing('stakeholderRoles');

            $data['role_details'] = $this->roleFields->check(
                $vendor->organization_id,
                $vendor->activeRoles()->pluck('role')->all(),
                $data['party_class'] ?? $vendor->party_class ?? 'organization',
                $data['role_details'],
                $vendor->role_details ?? [],
            );
        }

        $split = $this->onboarding->partitionUpdate($vendor, $data);

        $vendor = $this->service->update($vendor, $split['apply']);

        if ($split['pending'] !== []) {
            $this->onboarding->queueSensitiveChange($vendor, $split['pending'], $request->user()->id);
            $vendor->refresh();
        }

        return new VendorResource($vendor->load(['stakeholderRoles', 'documents', 'approvals']));
    }

    public function destroy(VendorPartner $vendor): JsonResponse
    {
        $this->authorize('delete', $vendor);
        $this->service->delete($vendor);
        return response()->json(null, 204);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Onboarding status transitions
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Put a draft into the approval chain.
     *
     * Still `update`: submitting is the last act of preparing a record, and
     * whoever registered a supplier should be able to hand it on for review.
     * Deciding it is what moved.
     */
    public function submitForReview(Request $request, VendorPartner $vendor): VendorResource
    {
        $this->authorize('update', $vendor);

        return new VendorResource(
            $this->guarded(fn () => $this->onboarding->submit($vendor, $request->user()->id))
                ->load(['stakeholderRoles', 'documents', 'approvals']),
        );
    }

    /** Sign the stage that is next. */
    public function approve(Request $request, VendorPartner $vendor): VendorResource
    {
        $this->authorizeDecision($request, $vendor);

        $data = $request->validate(['comment' => 'nullable|string|max:1000']);

        return new VendorResource(
            $this->guarded(fn () => $this->onboarding->decide(
                $vendor, 'approve', $request->user()->id, $data['comment'] ?? null,
            ))->load(['stakeholderRoles', 'documents', 'approvals']),
        );
    }

    /** Refuse it, with the reason on the record. */
    public function reject(Request $request, VendorPartner $vendor): VendorResource
    {
        $this->authorizeDecision($request, $vendor);

        $data = $request->validate(['reason' => 'required|string|max:1000']);

        return new VendorResource(
            $this->guarded(fn () => $this->onboarding->decide(
                $vendor, 'reject', $request->user()->id, $data['reason'],
            ))->load(['stakeholderRoles', 'documents', 'approvals']),
        );
    }

    public function suspend(Request $request, VendorPartner $vendor): VendorResource
    {
        $this->authorizeDecision($request, $vendor);

        $data = $request->validate(['reason' => 'required|string|max:1000']);

        return new VendorResource($this->guarded(fn () => $this->onboarding->suspend($vendor, $data['reason'])));
    }

    public function reactivate(Request $request, VendorPartner $vendor): VendorResource
    {
        $this->authorizeDecision($request, $vendor);

        return new VendorResource(
            $this->guarded(fn () => $this->onboarding->reactivate($vendor))
                ->load(['stakeholderRoles', 'documents']),
        );
    }

    /** Send a refused vendor back so it can be corrected and resubmitted. */
    public function returnToDraft(Request $request, VendorPartner $vendor): VendorResource
    {
        $this->authorize('update', $vendor);

        return new VendorResource($this->guarded(fn () => $this->onboarding->returnToDraft($vendor)));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Sensitive changes
    // ─────────────────────────────────────────────────────────────────────────

    public function confirmChange(Request $request, VendorPartner $vendor): VendorResource
    {
        $this->authorizeDecision($request, $vendor);

        return new VendorResource(
            $this->guarded(fn () => $this->onboarding->confirmSensitiveChange($vendor, $request->user()->id))
                ->load(['stakeholderRoles', 'documents', 'approvals']),
        );
    }

    public function discardChange(Request $request, VendorPartner $vendor): VendorResource
    {
        $this->authorizeDecision($request, $vendor);

        return new VendorResource(
            $this->guarded(fn () => $this->onboarding->discardSensitiveChange($vendor, $request->user()->id))
                ->load(['stakeholderRoles', 'documents', 'approvals']),
        );
    }

    /**
     * Deciding is its own permission.
     *
     * Registering a supplier and blessing it were the same permission until
     * now, which made the review a formality anybody could perform on their
     * own work. Tenancy still comes first — the policy answers that.
     */
    private function authorizeDecision(Request $request, VendorPartner $vendor): void
    {
        $this->authorize('view', $vendor);

        abort_unless($request->user()?->hasPermission(Permission::VENDORS_APPROVE), 403);
    }

    /**
     * Turn a refused transition into a 422 the caller can read.
     *
     * The service throws sentences — "a vendor that is draft cannot become
     * approved", "Tax Compliance Certificate expired on …" — and those are
     * exactly what the reviewer needs to see.
     */
    private function guarded(callable $action): VendorPartner
    {
        try {
            return $action();
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    /**
     * The questions a form should ask, for a given set of roles.
     *
     * Served rather than hard-coded in the client so that adding a question
     * is configuration, not a release — the same reason the asset form asks
     * the server what a category wants to know.
     */
    public function roleFields(Request $request): JsonResponse
    {
        $this->authorize('viewAny', VendorPartner::class);

        $data = $request->validate([
            // Comma separated rather than an array: a query string cannot
            // carry an empty array, and "this party holds no roles" is a real
            // answer that must not read as "roles unspecified".
            'roles'       => 'sometimes|nullable|string|max:400',
            'party_class' => 'sometimes|in:organization,individual',
        ]);

        /*
         * Naming no roles at all is a real answer — a party whose last role
         * ended is asked nothing role-specific. Not naming the parameter is
         * different, and means "everything", for the settings screen.
         */
        $roles = $request->has('roles')
            ? array_values(array_filter(array_map('trim', explode(',', (string) ($data['roles'] ?? '')))))
            : StakeholderRole::ROLES;

        $fields = \App\Models\Vendor\StakeholderRoleField::applyingTo(
            app('current.organization_id'),
            $roles,
            $data['party_class'] ?? 'organization',
        );

        return response()->json([
            'data' => $fields->map(fn ($f) => [
                'key'         => $f->key,
                'label'       => $f->label,
                'type'        => $f->type,
                'options'     => $f->options,
                'help'        => $f->help,
                'section'     => $f->section ?? 'Role details',
                'is_required' => $f->is_required,
                'role'        => $f->role,
            ])->values(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Contacts
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The people at this vendor.
     *
     * Not masked behind `accounts.view_contacts`: that classification
     * protects CUSTOMER contacts, whose details are commercially sensitive
     * about people we sell to. A supplier's sales rep is who you ring to
     * chase a delivery, and anyone who can see the vendor needs them.
     */
    public function contacts(VendorPartner $vendor): JsonResponse
    {
        $this->authorize('view', $vendor);

        return response()->json(['data' => $vendor->contacts()->get()->map(fn (VendorContact $c) => $this->contactPayload($c))]);
    }

    public function storeContact(Request $request, VendorPartner $vendor): JsonResponse
    {
        $this->authorize('update', $vendor);

        $contact = $this->service->addContact(
            $vendor, $this->validatedContact($request), $request->user()->id,
        );

        return response()->json(['data' => $this->contactPayload($contact)], 201);
    }

    public function updateContact(Request $request, VendorPartner $vendor, VendorContact $contact): JsonResponse
    {
        $this->authorize('update', $vendor);
        abort_if($contact->vendor_partner_id !== $vendor->id, 404);

        $updated = $this->service->updateContact($contact, $this->validatedContact($request, true));

        return response()->json(['data' => $this->contactPayload($updated)]);
    }

    public function destroyContact(VendorPartner $vendor, VendorContact $contact): JsonResponse
    {
        $this->authorize('update', $vendor);
        abort_if($contact->vendor_partner_id !== $vendor->id, 404);

        $this->service->deleteContact($contact);

        return response()->json(null, 204);
    }

    private function validatedContact(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'category'        => 'sometimes|in:' . implode(',', VendorContact::CATEGORIES),
            'title'           => 'nullable|string|max:32',
            'first_name'      => $required . '|string|max:128',
            'last_name'       => 'nullable|string|max:128',
            'designation'     => 'nullable|string|max:128',
            'department'      => 'nullable|string|max:128',
            'email'           => 'nullable|email|max:255',
            'phone'           => 'nullable|string|max:32',
            'alternate_phone' => 'nullable|string|max:32',
            'is_active'       => 'boolean',
        ]);
    }

    private function contactPayload(VendorContact $contact): array
    {
        return [
            'id'              => $contact->id,
            'category'        => $contact->category,
            'title'           => $contact->title,
            'first_name'      => $contact->first_name,
            'last_name'       => $contact->last_name,
            'full_name'       => $contact->full_name,
            'designation'     => $contact->designation,
            'department'      => $contact->department,
            'email'           => $contact->email,
            'phone'           => $contact->phone,
            'alternate_phone' => $contact->alternate_phone,
            'is_primary'      => $contact->is_primary,
            'is_active'       => $contact->is_active,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Classification
    // ─────────────────────────────────────────────────────────────────────────

    /** Start a role, from today or a stated date. */
    public function addRole(Request $request, VendorPartner $vendor): VendorResource
    {
        $this->authorize('update', $vendor);

        $data = $request->validate([
            'role'       => 'required|string|in:' . implode(',', StakeholderRole::ROLES),
            'valid_from' => 'nullable|date',
            'notes'      => 'nullable|string|max:500',
        ]);

        $this->assertRolesSuitClass([$data['role']], $vendor->party_class ?? 'organization');

        $this->service->addRole(
            $vendor, $data['role'], $data['valid_from'] ?? null,
            $data['notes'] ?? null, $request->user()->id,
        );

        return new VendorResource($vendor->fresh('stakeholderRoles'));
    }

    /**
     * End a role — never delete it.
     *
     * What somebody was when a contract was signed is exactly the question
     * this table exists to answer, so the row stays and gains an end date.
     */
    public function endRole(Request $request, VendorPartner $vendor, StakeholderRole $role): VendorResource
    {
        $this->authorize('update', $vendor);
        abort_if($role->vendor_partner_id !== $vendor->id, 404);

        $data = $request->validate(['valid_until' => 'nullable|date']);

        $this->service->endRole($role, $data['valid_until'] ?? null);

        return new VendorResource($vendor->fresh('stakeholderRoles'));
    }

    /**
     * An individual cannot be a distributor, and a company cannot be an
     * individual contractor. Refusing here keeps the register honest rather
     * than leaving the contradiction for a report to trip over.
     */
    private function assertRolesSuitClass(array $roles, string $partyClass): void
    {
        foreach ($roles as $role) {
            $individualOnly = in_array($role, StakeholderRole::INDIVIDUAL_ONLY, true);

            if ($individualOnly && $partyClass !== 'individual') {
                abort(422, "The role \"{$role}\" belongs to a person, not an organisation.");
            }

            if (! $individualOnly && $partyClass === 'individual'
                && in_array($role, ['distributor', 'oem', 'channel_partner'], true)) {
                abort(422, "An individual cannot hold the role \"{$role}\".");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Metrics
    // ─────────────────────────────────────────────────────────────────────────

    public function metrics(): JsonResponse
    {
        $this->authorize('viewAny', VendorPartner::class);
        return response()->json(['data' => $this->service->metrics(app('current.organization_id'))]);
    }
}
