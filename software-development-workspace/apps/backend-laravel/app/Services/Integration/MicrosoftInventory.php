<?php

namespace App\Services\Integration;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reading what Microsoft already knows about our estate.
 *
 * Two APIs, one set of credentials, and the difference between them is the
 * thing most likely to waste an afternoon: Microsoft Graph and Azure Resource
 * Manager take tokens minted for *different audiences*. The same client id and
 * secret produce two different tokens, and each is rejected by the other
 * service. Hence a broker keyed by audience rather than a single token.
 *
 * Everything here is read-only, and deliberately so — see the note in
 * config/services.php about why this principal must never be granted write.
 */
class MicrosoftInventory
{
    private const GRAPH = 'https://graph.microsoft.com';
    private const ARM   = 'https://management.azure.com';

    public function __construct(private readonly LicenceSkus $skus = new LicenceSkus()) {}

    // ── Tokens ────────────────────────────────────────────────────────────────

    /**
     * A token for one audience, cached just under its hour of life.
     *
     * 3,500 seconds rather than 3,600: a token fetched at the boundary and used
     * a few seconds later would otherwise arrive expired, which fails as an
     * unauthorised response rather than anything that names the real cause.
     */
    private function token(string $audience): string
    {
        $tenant = $this->config('tenant_id');
        $client = $this->config('client_id');
        $secret = $this->config('client_secret');

        return Cache::remember(
            'ms_inventory_token_' . md5($audience . $client . $tenant),
            3500,
            function () use ($audience, $tenant, $client, $secret): string {
                $response = Http::asForm()->timeout(15)->post(
                    "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
                    [
                        'grant_type'    => 'client_credentials',
                        'client_id'     => $client,
                        'client_secret' => $secret,
                        'scope'         => $audience . '/.default',
                    ],
                );

                if ($response->failed()) {
                    throw new RuntimeException(
                        'Microsoft token request failed: '
                        . ($response->json('error_description') ?? $response->body()),
                    );
                }

                return (string) $response->json('access_token');
            },
        );
    }

    private function config(string $key): string
    {
        $value = config("services.ms_inventory.{$key}");

        if (blank($value)) {
            throw new RuntimeException(
                "services.ms_inventory.{$key} is not set. The inventory sync needs its own "
                . 'app registration — see config/services.php.',
            );
        }

        return (string) $value;
    }

    /** Which permissions the token actually carries, for diagnosis. */
    public function grantedRoles(): array
    {
        $parts = explode('.', $this->token(self::GRAPH));
        $claims = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')) ?: '{}', true);

        return $claims['roles'] ?? [];
    }

    // ── Microsoft 365 licences ────────────────────────────────────────────────

    /**
     * Every SKU the tenant subscribes to, classified.
     *
     * `consumedUnits` is Microsoft's count of assigned seats. It is kept here
     * as `consumed_reported` rather than becoming our seat count, because the
     * register derives seats-in-use from allocations it can name a person for.
     * Where the two disagree, the difference is the finding — an account we
     * could not match to anybody.
     */
    public function subscribedSkus(): array
    {
        $response = Http::withToken($this->token(self::GRAPH))
            ->timeout(30)
            ->get(self::GRAPH . '/v1.0/subscribedSkus');

        $this->assertOk($response, 'subscribedSkus');

        return collect($response->json('value') ?? [])
            ->map(function (array $sku): array {
                $part     = (string) ($sku['skuPartNumber'] ?? '');
                $prepaid  = (int) ($sku['prepaidUnits']['enabled'] ?? 0);
                $consumed = (int) ($sku['consumedUnits'] ?? 0);

                return [
                    'sku_id'            => $sku['skuId'] ?? null,
                    'part_number'       => $part,
                    'name'              => $this->skus->name($part),
                    'classification'    => $this->skus->classify($part, $prepaid),
                    'seats_total'       => $prepaid,
                    'consumed_reported' => $consumed,
                    'seats_spare'       => max(0, $prepaid - $consumed),
                    'suspended'         => (int) ($sku['prepaidUnits']['suspended'] ?? 0),
                    'capability_status' => $sku['capabilityStatus'] ?? null,
                    'service_plans'     => collect($sku['servicePlans'] ?? [])
                        ->pluck('servicePlanName')->filter()->values()->all(),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Everybody holding a licence, and which.
     *
     * Paged, because a tenant of any size exceeds one response and a sync that
     * silently reads only the first hundred users would report most of the
     * estate as unassigned — which looks like a saving and is a bug.
     */
    public function licensedUsers(): array
    {
        $url = self::GRAPH . '/v1.0/users?$select=id,displayName,userPrincipalName,mail,'
            . 'accountEnabled,assignedLicenses&$top=999';

        $token = $this->token(self::GRAPH);
        $users = [];

        while ($url !== null) {
            $response = Http::withToken($token)->timeout(60)->get($url);
            $this->assertOk($response, 'users');

            foreach ($response->json('value') ?? [] as $user) {
                $licences = collect($user['assignedLicenses'] ?? [])->pluck('skuId')->filter();

                if ($licences->isEmpty()) {
                    continue;
                }

                $users[] = [
                    'id'          => $user['id'] ?? null,
                    'name'        => $user['displayName'] ?? null,
                    'upn'         => $user['userPrincipalName'] ?? null,
                    'mail'        => $user['mail'] ?? null,
                    'enabled'     => (bool) ($user['accountEnabled'] ?? true),
                    'sku_ids'     => $licences->values()->all(),
                ];
            }

            $url = $response->json('@odata.nextLink');
        }

        return $users;
    }

    /**
     * Renewal dates, where the purchase channel reports them.
     *
     * Not every channel does — a CSP or enterprise agreement may carry none —
     * so a missing date here means "not published", not "does not renew". The
     * register keeps its manual field for exactly that reason.
     */
    public function commercialSubscriptions(): array
    {
        $response = Http::withToken($this->token(self::GRAPH))
            ->timeout(30)
            ->get(self::GRAPH . '/v1.0/directory/subscriptions');

        if ($response->status() === 404) {
            return [];
        }

        $this->assertOk($response, 'directory/subscriptions');

        return collect($response->json('value') ?? [])->map(fn (array $s): array => [
            'sku_id'      => $s['skuId'] ?? null,
            'part_number' => $s['skuPartNumber'] ?? null,
            'status'      => $s['status'] ?? null,
            'is_trial'    => (bool) ($s['isTrial'] ?? false),
            'total'       => (int) ($s['totalLicenses'] ?? 0),
            'renews_on'   => isset($s['nextLifecycleDateTime'])
                ? substr((string) $s['nextLifecycleDateTime'], 0, 10)
                : null,
        ])->all();
    }

    // ── Azure resources ───────────────────────────────────────────────────────

    /**
     * The Azure estate, through Resource Graph.
     *
     * One KQL query spans every subscription the principal can see, rather than
     * walking ARM provider by provider — which is dozens of calls and misses
     * any resource type nobody remembered to enumerate.
     *
     * The ARM resource id is the natural key: globally unique and stable across
     * renames, so a re-run updates rather than duplicating.
     */
    public function azureResources(?string $subscriptionId = null): array
    {
        $subscription = $subscriptionId ?: $this->config('subscription_id');

        $response = Http::withToken($this->token(self::ARM))
            ->timeout(60)
            ->post(self::ARM . '/providers/Microsoft.ResourceGraph/resources?api-version=2022-10-01', [
                'subscriptions' => [$subscription],
                'query' => 'Resources | project id, name, type, location, resourceGroup, '
                    . 'subscriptionId, sku, tags, kind | order by type asc, name asc',
            ]);

        $this->assertOk($response, 'Resource Graph');

        return collect($response->json('data') ?? [])->map(fn (array $r): array => [
            'resource_id'    => $r['id'] ?? null,
            'name'           => $r['name'] ?? null,
            'type'           => $r['type'] ?? null,
            'location'       => $r['location'] ?? null,
            'resource_group' => $r['resourceGroup'] ?? null,
            'subscription'   => $r['subscriptionId'] ?? null,
            'sku'            => $r['sku']['name'] ?? ($r['sku']['tier'] ?? null),
            'kind'           => $r['kind'] ?? null,
            'tags'           => is_array($r['tags'] ?? null) ? $r['tags'] : [],
        ])->all();
    }

    // ── Shared ────────────────────────────────────────────────────────────────

    /**
     * Fail with the reason Microsoft gave, not a status code.
     *
     * A 403 from Graph and a 403 from ARM mean different things and are fixed
     * in different places — one is an admin-consent grant, the other an RBAC
     * role assignment — and the message is what distinguishes them.
     */
    private function assertOk(\Illuminate\Http\Client\Response $response, string $what): void
    {
        if ($response->successful()) {
            return;
        }

        $code    = $response->json('error.code') ?? $response->json('error') ?? '';
        $message = $response->json('error.message') ?? $response->body();

        throw new RuntimeException(
            "Microsoft {$what} failed [{$response->status()} {$code}]: " . substr((string) $message, 0, 300),
        );
    }
}
