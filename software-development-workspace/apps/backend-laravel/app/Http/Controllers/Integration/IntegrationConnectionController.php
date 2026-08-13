<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Models\Integration\IntegrationConnection;
use App\Services\Integration\MicrosoftInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/**
 * The tenants and subscriptions the register reads from.
 */
class IntegrationConnectionController extends Controller
{
    public function __construct(private readonly MicrosoftInventory $inventory) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', IntegrationConnection::class);

        return response()->json([
            'data' => IntegrationConnection::with('subscriptions')
                ->orderBy('name')
                ->get()
                ->map(fn (IntegrationConnection $c) => $this->payload($c)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', IntegrationConnection::class);

        $data = $this->validated($request, null);

        $connection = new IntegrationConnection($data);
        $connection->organization_id = $this->organizationId();
        $connection->created_by      = $request->user()->id;
        $connection->save();

        return response()->json(['data' => $this->payload($connection->fresh('subscriptions'))], 201);
    }

    public function update(Request $request, IntegrationConnection $connection): JsonResponse
    {
        $this->authorize('update', $connection);

        $data = $this->validated($request, $connection);

        /*
         * An absent secret means "leave it alone", not "clear it".
         *
         * The screen cannot show the current value — it is never sent — so an
         * edit that only changes the name submits a blank secret field. Taking
         * that literally would break the connection every time somebody fixed
         * a typo.
         */
        if (blank($data['client_secret'] ?? null)) {
            unset($data['client_secret']);
        }

        $connection->update($data);

        return response()->json(['data' => $this->payload($connection->fresh('subscriptions'))]);
    }

    public function destroy(IntegrationConnection $connection): JsonResponse
    {
        $this->authorize('delete', $connection);

        $connection->delete();

        return response()->json(['message' => 'Connection removed. Nothing already imported was touched.']);
    }

    /**
     * Try the credentials and say what they can actually do.
     *
     * The useful answer is not "connected" but *which permissions were granted*
     * — a tenant with the licence roles and no ARM Reader looks identical to a
     * working one until an Azure sync 403s, and by then the person who granted
     * consent has moved on to something else.
     */
    public function test(IntegrationConnection $connection): JsonResponse
    {
        $this->authorize('update', $connection);

        $reader = $this->inventory->forConnection($connection);

        try {
            $roles = $reader->grantedRoles();

            $checks = [
                'licences' => $this->probe(fn () => count($reader->subscribedSkus())),
                'people'   => $this->probe(fn () => count($reader->licensedUsers())),
                'azure'    => $connection->sync_azure
                    ? $this->probe(fn () => count($reader->discoverSubscriptions()))
                    : null,
            ];

            /*
             * A partial refusal is a result, not a failure to answer.
             *
             * The credentials worked — some of what they were asked to do was
             * declined, and *which* is the whole value of this screen. So this
             * returns 200 with a verdict per capability rather than one 422
             * that collapses "Graph consent missing" and "no Reader on the
             * subscription" into the same red box. A 422 is kept for the case
             * where nothing could be established at all.
             */
            $failure = collect($checks)->filter()->firstWhere('ok', false);

            $connection->update([
                'last_tested_at'    => now(),
                'last_test_status'  => $failure
                    ? IntegrationConnection::STATUS_FAILED
                    : IntegrationConnection::STATUS_OK,
                'last_test_message' => $failure['message'] ?? 'Credentials accepted.',
                'granted_roles'     => $roles,
            ]);

            return response()->json([
                'data'    => $this->payload($connection->fresh('subscriptions')),
                'roles'   => $roles,
                'checks'  => $checks,
                'message' => $failure['message'] ?? 'Credentials accepted.',
            ]);
        } catch (Throwable $e) {
            $connection->update([
                'last_tested_at'    => now(),
                'last_test_status'  => IntegrationConnection::STATUS_FAILED,
                'last_test_message' => $this->readable($e),
            ]);

            return response()->json([
                'data'    => $this->payload($connection->fresh('subscriptions')),
                'message' => $this->readable($e),
            ], 422);
        }
    }

    /**
     * Ask the tenant which subscriptions this principal can see.
     *
     * Found is not the same as wanted, so newly discovered rows arrive
     * unticked. Existing rows keep whatever was chosen for them — re-running
     * discovery to pick up a new subscription must not silently switch off the
     * ones already syncing.
     */
    public function discover(IntegrationConnection $connection): JsonResponse
    {
        $this->authorize('update', $connection);

        try {
            $found = $this->inventory->forConnection($connection)->discoverSubscriptions();
        } catch (Throwable $e) {
            return response()->json(['message' => $this->readable($e)], 422);
        }

        foreach ($found as $subscription) {
            $connection->subscriptions()->updateOrCreate(
                ['subscription_id' => $subscription['subscription_id']],
                [
                    'display_name' => $subscription['display_name'],
                    'state'        => $subscription['state'],
                ],
            );
        }

        return response()->json([
            'data'    => $this->payload($connection->fresh('subscriptions')),
            'message' => count($found) . ' subscription(s) visible to this principal.',
        ]);
    }

    /** Tick or untick which subscriptions this connection imports. */
    public function setSubscriptions(Request $request, IntegrationConnection $connection): JsonResponse
    {
        $this->authorize('update', $connection);

        $data = $request->validate([
            'enabled'   => ['present', 'array'],
            'enabled.*' => ['string'],
        ]);

        $connection->subscriptions()->update(['is_enabled' => false]);

        if ($data['enabled'] !== []) {
            $connection->subscriptions()
                ->whereIn('subscription_id', $data['enabled'])
                ->update(['is_enabled' => true]);
        }

        return response()->json(['data' => $this->payload($connection->fresh('subscriptions'))]);
    }

    // ── Plumbing ──────────────────────────────────────────────────────────────

    /**
     * Which organisation's register this is, refusing to guess.
     *
     * `app('current.organization_id')` cannot be read directly. The middleware
     * binds *null* for a group-scope user who sent no X-Organization header,
     * and Laravel's container tests bindings with isset() — so a null instance
     * reads as unbound and `app()` then tries to resolve the string as a class
     * name. The failure is "Target class [current.organization_id] does not
     * exist", which says nothing about the actual cause.
     *
     * Null is also a real answer here and not an acceptable one: a group admin
     * looking across every organisation has not said which one a tenant's
     * estate belongs to, and picking for them would file another company's
     * servers in the wrong register.
     */
    private function organizationId(): int
    {
        $id = app()->bound('current.organization_id')
            ? app('current.organization_id')
            : null;

        abort_if(
            $id === null,
            422,
            'Choose which organisation this tenant belongs to before adding it.',
        );

        return (int) $id;
    }

    private function validated(Request $request, ?IntegrationConnection $existing): array
    {
        /*
         * "The tenant id has already been taken" is true and useless.
         *
         * A tenant may only be registered once per organisation, so hitting
         * this means the connection is already there — usually the one already
         * syncing. Naming it, and saying to edit it, is the difference between
         * a dead end and a next step.
         */
        $clash = IntegrationConnection::withoutGlobalScope('organization')
            ->where('organization_id', $this->organizationId())
            ->where('tenant_id', $request->input('tenant_id'))
            ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))
            ->first();

        return $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'provider'  => ['nullable', Rule::in([IntegrationConnection::PROVIDER_MICROSOFT])],
            'tenant_id' => [
                'required', 'uuid',
                Rule::unique('integration_connections', 'tenant_id')
                    ->where('organization_id', $this->organizationId())
                    ->whereNull('deleted_at')
                    ->ignore($existing?->id),
            ],
            'client_id'         => ['required', 'uuid'],
            'client_secret'     => [$existing ? 'nullable' : 'required', 'string', 'max:500'],
            'secret_expires_on' => ['nullable', 'date'],
            'sync_licences'     => ['boolean'],
            'sync_azure'        => ['boolean'],
            'is_active'         => ['boolean'],
        ], [
            'tenant_id.unique' => $clash
                ? "That tenant is already connected here as \"{$clash->name}\". "
                    . 'Edit that one instead — adding it twice would import every licence twice.'
                : 'That tenant is already connected to this organisation.',
        ]);
    }

    /**
     * Never includes the secret — see the note on the model.
     *
     * `has_secret` is what the screen needs: whether one is set, so the edit
     * form can say "leave blank to keep the current one" rather than implying
     * the field is empty because nothing was saved.
     */
    private function payload(IntegrationConnection $connection): array
    {
        return [
            'id'                => $connection->id,
            'name'              => $connection->name,
            'provider'          => $connection->provider,
            'tenant_id'         => $connection->tenant_id,
            'client_id'         => $connection->client_id,
            'has_secret'        => $connection->hasSecret(),
            'secret_expires_on' => $connection->secret_expires_on?->toDateString(),
            'secret_days_left'  => $connection->daysToSecretExpiry(),
            'secret_expiring'   => $connection->secretExpiringSoon(),
            'sync_licences'     => $connection->sync_licences,
            'sync_azure'        => $connection->sync_azure,
            'is_active'         => $connection->is_active,
            'last_tested_at'    => $connection->last_tested_at?->toDateTimeString(),
            'last_test_status'  => $connection->last_test_status,
            'last_test_message' => $connection->last_test_message,
            'granted_roles'     => $connection->granted_roles ?? [],
            'last_synced_at'    => $connection->last_synced_at?->toDateTimeString(),
            'subscriptions'     => $connection->subscriptions->map(fn ($s) => [
                'id'              => $s->id,
                'subscription_id' => $s->subscription_id,
                'display_name'    => $s->display_name,
                'state'           => $s->state,
                'is_enabled'      => $s->is_enabled,
                'resource_count'  => $s->resource_count,
                'last_synced_at'  => $s->last_synced_at?->toDateTimeString(),
            ])->values(),
        ];
    }

    /** @return array{ok: bool, count: int|null, message: string|null} */
    private function probe(callable $call): array
    {
        try {
            return ['ok' => true, 'count' => (int) $call(), 'message' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'count' => null, 'message' => $this->readable($e)];
        }
    }

    /**
     * Microsoft's messages carry a correlation id and a support paragraph.
     *
     * The part that helps is which of the two permission systems refused: a
     * Graph 403 is an admin-consent grant on the app registration, an ARM 403
     * is an RBAC role assignment on a subscription, and they are fixed in
     * different places by different people.
     */
    private function readable(Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'Resource Graph') || str_contains($message, 'subscriptions failed')) {
            if (str_contains($message, '403')) {
                return 'Azure refused: this principal has no Reader role on the subscription. '
                    . 'That is an RBAC role assignment, not an API permission.';
            }
        }

        if (str_contains($message, 'Authorization_RequestDenied') || str_contains($message, '[403')) {
            return 'Microsoft Graph refused: the app registration is missing admin consent for '
                . 'Organization.Read.All, User.Read.All or Directory.Read.All.';
        }

        if (str_contains($message, 'invalid_client') || str_contains($message, 'AADSTS7000215')) {
            return 'The client secret was not accepted. It may have expired or been mistyped.';
        }

        if (str_contains($message, 'AADSTS700016') || str_contains($message, 'unauthorized_client')) {
            return 'No application with that client id exists in this tenant.';
        }

        return $e instanceof RuntimeException ? $message : 'Could not reach Microsoft.';
    }
}
