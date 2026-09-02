<?php

namespace App\Services\Identity;

use App\Models\Integration\IntegrationConnection;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Asking Microsoft whether a person has an Office 365 account.
 *
 * The answer decides which way in they get: everybody Entra knows and has
 * enabled signs in through Entra, and everybody else needs a local username
 * and a one-time code. That is not a preference somebody sets — it is a fact
 * about the tenant, so it is asked rather than assumed, and the time it was
 * asked is stored beside the answer.
 *
 * **Three distinct outcomes, deliberately not collapsed into two.** Not found
 * and found-but-disabled both mean "local path", but they are different facts:
 * one is a person who was never given an account, the other is a leaver or a
 * suspension. An administrator chasing a problem needs the actual one.
 *
 * A fourth outcome — error — is the one that must never be mistaken for an
 * answer. A 403 because the app registration lacks `User.Read.All` would
 * otherwise read as "nobody is in Office 365" and quietly move the whole
 * organisation onto the local path.
 *
 * App-only, not delegated: this runs on a schedule and for people who are not
 * signed in. Needs `User.Read.All` granted with admin consent on the app
 * registration the organisation names as its **directory** connection —
 * deliberately not the one that reads mailboxes and books meetings. One leaked
 * secret should not reach the directory, the mail, and the authentication
 * methods all at once.
 */
class DirectoryDiscovery
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    public function __construct(private readonly IntegrationConnection $connection)
    {
    }

    /**
     * Which registration reads the directory.
     *
     * Named on the organisation, not guessed: this used to take the first
     * active Microsoft connection it found, which was fine while there was one
     * and wrong the moment there were two. Reading the directory needs
     * `User.Read.All`, and what comes after it needs the ability to change how
     * people authenticate — permissions that belong on a registration of their
     * own rather than on the one that also reads every mailbox.
     *
     * Discovery without a connection is an unasked question, not a failed
     * lookup, and the difference matters: the first would mark everybody local.
     */
    public static function forOrganization(int $organizationId): self
    {
        $org = Organization::findOrFail($organizationId);

        $connection = $org->identity_connection_id
            ? IntegrationConnection::where('id', $org->identity_connection_id)
                ->where('is_active', true)->first()
            : null;

        if (! $connection) {
            throw new RuntimeException(
                $org->identity_connection_id
                    ? 'The connection named for directory lookups is inactive or gone. Choose one under Connected Tenants.'
                    : 'No connection has been named for directory lookups. Under Connected Tenants, pick the app '
                        . "registration that holds 'User.Read.All' and mark it as the directory connection.",
            );
        }

        return new self($connection);
    }

    /** Which registration answered, so a result is never anonymous. */
    public function connectionName(): string
    {
        return $this->connection->name;
    }

    /**
     * Look one person up and record what came back.
     *
     * Matching is on `userPrincipalName`/`mail` for the lookup but the identity
     * kept is `id` — Entra's immutable object id. Addresses and display names
     * change; the oid does not, and the login path already matches on it.
     *
     * @return array{status: string, enabled: bool|null, oid: string|null, error: string|null}
     */
    public function look(User $user): array
    {
        $address = $user->email;

        $response = Http::withToken($this->token())->timeout(15)->acceptJson()
            ->get(self::GRAPH . '/users/' . rawurlencode($address), [
                '$select' => 'id,userPrincipalName,accountEnabled,mail,displayName',
            ]);

        /*
         * A 404 is not yet an answer.
         *
         * `GET /users/{key}` accepts an object id or a userPrincipalName and
         * nothing else. Somebody whose sign-in name is `x@kns.co.ke` but whose
         * mail attribute is `x@konvergenz.co.ug` — a second domain, a guest, a
         * migrated account — returns 404 from the direct path while existing
         * perfectly well. Five of the eight "not found" here are on a second
         * domain, which is exactly that shape.
         *
         * So a miss falls back to searching the mail attribute before
         * concluding anything.
         */
        if ($response->status() === 404) {
            return $this->lookByMail($address);
        }

        if ($response->failed()) {
            /*
             * Not an answer. A 403 here means the app registration is missing
             * `User.Read.All` — treating that as "not in Office 365" would move
             * everybody onto the local path on the strength of our own
             * misconfiguration.
             */
            $message = $response->json('error.message') ?? $response->body();

            Log::warning('Directory discovery failed', [
                'user' => $user->id, 'status' => $response->status(), 'body' => $response->body(),
            ]);

            return [
                'status' => User::DISCOVERY_ERROR,
                'enabled' => null,
                'oid' => null,
                'error' => $response->status() === 403
                    ? "Microsoft refused the lookup. The app registration needs 'User.Read.All' "
                        . 'granted with admin consent before discovery can run.'
                    : (string) $message,
            ];
        }

        $body = $response->json();
        $enabled = (bool) ($body['accountEnabled'] ?? false);

        return [
            'status'  => $enabled ? User::DISCOVERY_ENABLED : User::DISCOVERY_DISABLED,
            'enabled' => $enabled,
            'oid'     => $body['id'] ?? null,
            'error'   => null,
        ];
    }

    /**
     * Second attempt: find them by the address we hold, wherever it sits.
     *
     * `$filter` on `mail` and on `otherMails`, because a person reachable at an
     * address is often not signed in as it. Anything but a single unambiguous
     * hit is treated as not found: two accounts sharing an address is a
     * question for a human, and picking one would be a guess that decides how
     * somebody authenticates.
     *
     * @return array{status: string, enabled: bool|null, oid: string|null, error: string|null}
     */
    private function lookByMail(string $address): array
    {
        $escaped = str_replace("'", "''", $address);

        $response = Http::withToken($this->token())->timeout(15)->acceptJson()
            ->withHeaders(['ConsistencyLevel' => 'eventual'])
            ->get(self::GRAPH . '/users', [
                '$filter' => "mail eq '{$escaped}' or otherMails/any(m:m eq '{$escaped}')",
                '$select' => 'id,userPrincipalName,accountEnabled,mail',
                '$count'  => 'true',
                '$top'    => 2,
            ]);

        if ($response->failed()) {
            return [
                'status'  => User::DISCOVERY_ERROR,
                'enabled' => null,
                'oid'     => null,
                'error'   => $response->status() === 403
                    ? "Microsoft refused the lookup. The app registration needs 'User.Read.All' "
                        . 'granted with admin consent before discovery can run.'
                    : (string) ($response->json('error.message') ?? $response->body()),
            ];
        }

        $matches = $response->json('value') ?? [];

        if (count($matches) !== 1) {
            // None, or more than one. Both mean nobody can be named with
            // confidence, and only the first is "not in Office 365".
            return [
                'status'  => User::DISCOVERY_MISSING,
                'enabled' => null,
                'oid'     => null,
                'error'   => count($matches) > 1
                    ? 'More than one Office 365 account carries this address, so none was chosen.'
                    : null,
            ];
        }

        $enabled = (bool) ($matches[0]['accountEnabled'] ?? false);

        return [
            'status'  => $enabled ? User::DISCOVERY_ENABLED : User::DISCOVERY_DISABLED,
            'enabled' => $enabled,
            'oid'     => $matches[0]['id'] ?? null,
            'error'   => null,
        ];
    }

    /**
     * Look somebody up and write the conclusion onto them.
     *
     * The method is only changed where discovery actually answered. An error
     * leaves whatever was decided before standing — including a method an
     * administrator set by hand, which a failed lookup has no business
     * overturning.
     */
    public function reconcile(User $user): User
    {
        $result = $this->look($user);

        $changes = [
            'discovery_status'  => $result['status'],
            'account_enabled'   => $result['enabled'],
            'last_discovery_at' => now(),
            'discovery_error'   => $result['error'],
        ];

        if ($result['status'] === User::DISCOVERY_ENABLED) {
            $changes['auth_method'] = User::AUTH_SSO;
            $changes['sso_only']    = true;

            if ($result['oid']) {
                $changes['azure_id'] = $result['oid'];
            }
        } elseif (in_array($result['status'], [User::DISCOVERY_MISSING, User::DISCOVERY_DISABLED], true)) {
            $changes['auth_method'] = User::AUTH_LOCAL_TOTP;
            $changes['sso_only']    = false;

            // A default rather than a decision: the authenticator is the
            // stronger factor, and email remains available as a fallback.
            $changes['otp_preference'] ??= $user->otp_preference ?? User::OTP_AUTHENTICATOR;
        }

        $user->update($changes);

        return $user->fresh();
    }

    /**
     * A token for the whole tenant, not one person.
     *
     * Cached just under the hour Microsoft issues them for, and keyed per
     * connection so two tenants never share one.
     */
    private function token(): string
    {
        $key = 'ms_graph_directory_token_' . $this->connection->id;

        return Cache::remember($key, 3500, function (): string {
            $response = Http::asForm()->timeout(10)->post(
                "https://login.microsoftonline.com/{$this->connection->tenant_id}/oauth2/v2.0/token",
                [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $this->connection->client_id,
                    'client_secret' => $this->connection->client_secret,
                    'scope'         => 'https://graph.microsoft.com/.default',
                ],
            );

            if ($response->failed()) {
                throw new RuntimeException('Microsoft token request failed: ' . $response->body());
            }

            return $response->json('access_token');
        });
    }
}
