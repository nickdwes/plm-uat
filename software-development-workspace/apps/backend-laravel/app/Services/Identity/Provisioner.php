<?php

namespace App\Services\Identity;

use App\Models\Employee;
use App\Models\Integration\IntegrationConnection;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Giving somebody access, the other half of Deprovisioner.
 *
 * Two directions, because the registers drift apart in both:
 *
 *  - **Onboarded here, absent there.** Somebody starts, gets an employee
 *    record and a login, and then waits on a separate request to IT for the
 *    mailbox they cannot do the job without. `provision()` creates the Entra
 *    account from the record we already hold.
 *  - **Present there, absent here.** An account exists in Office 365 that no
 *    employee record explains — a starter IT set up first, a service account,
 *    or a leaver nobody told HR about. `unmatched()` lists them so somebody
 *    decides, and each one carries the details to create the profile from.
 *
 * Deliberately never automatic in the second direction. Creating employee
 * records off the back of a directory sync is how a shared mailbox becomes a
 * person on the org chart; a suggestion a human accepts is the whole design.
 *
 * Needs `User.ReadWrite.All`, the same grant Deprovisioner uses. Where the
 * tenant has not granted it, this says so plainly rather than half-creating.
 */
class Provisioner
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    public function __construct(private readonly ?IntegrationConnection $connection) {}

    public static function forOrganization(int $organizationId): self
    {
        $org = Organization::find($organizationId);

        $connection = $org?->identity_connection_id
            ? IntegrationConnection::where('id', $org->identity_connection_id)
                ->where('is_active', true)->first()
            : null;

        return new self($connection);
    }

    public function isConnected(): bool
    {
        return $this->connection !== null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // HRMS → Office 365
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Create this person's Office 365 account.
     *
     * @param  string|null  $upn  Override the sign-in name; theirs by default.
     * @return array{status:string, message:string, azure_id:?string, upn:?string, temporary_password:?string}
     */
    public function provision(User $user, ?string $upn = null, ?string $usageLocation = null): array
    {
        if (! $this->connection) {
            throw new RuntimeException('No Office 365 connection is configured for this organisation.');
        }

        if ($user->azure_id) {
            return $this->already($user->azure_id, $user->email, 'This login is already linked to an Office 365 account.');
        }

        $upn = $upn ?: $user->email;

        if (! $upn || ! str_contains($upn, '@')) {
            throw new RuntimeException('This login has no address to sign in with.');
        }

        /*
         * Ask before creating.
         *
         * Two accounts for one person is worse than none: mail splits between
         * them, and whichever one licensing lands on is the one that works.
         * Discovery already knows how to find somebody whose mail attribute
         * differs from their sign-in name, so it answers this rather than a
         * bare existence check that would miss exactly those.
         */
        $existing = $this->findInDirectory($upn);

        if ($existing !== null) {
            $this->link($user, $existing['id']);

            return $this->already($existing['id'], $existing['userPrincipalName'] ?? $upn,
                'That account already exists in Office 365 — linked to this login instead of creating a second one.');
        }

        $employee = $user->employee;
        $password = $this->temporaryPassword();

        $payload = array_filter([
            'accountEnabled'    => true,
            'displayName'       => $user->name ?: trim(($employee?->first_name . ' ' . $employee?->last_name)) ?: $upn,
            'mailNickname'      => $this->mailNickname($upn),
            'userPrincipalName' => $upn,
            'givenName'         => $employee?->first_name,
            'surname'           => $employee?->last_name,
            'jobTitle'          => $employee?->designation?->title,
            'department'        => $employee?->department?->name,
            'mobilePhone'       => $employee?->phone,
            // Required before any licence can be assigned. Without it the
            // account is created and then refuses every licence, which reads
            // as a licensing fault rather than a missing attribute.
            'usageLocation'     => $usageLocation ?: $this->usageLocationFor($employee),
            'passwordProfile'   => [
                'password'                      => $password,
                'forceChangePasswordNextSignIn' => true,
            ],
        ], fn ($v) => $v !== null && $v !== '');

        $response = Http::withToken($this->token())->timeout(20)->acceptJson()
            ->post(self::GRAPH . '/users', $payload);

        if ($response->status() === 403) {
            throw new RuntimeException(
                'Office 365 refused: the app registration is missing User.ReadWrite.All.'
            );
        }

        if ($response->failed()) {
            // Graph's own message names the attribute it disliked, which is
            // far more use than "creation failed".
            throw new RuntimeException(
                'Office 365 refused to create the account: '
                . ($response->json('error.message') ?? $response->body())
            );
        }

        $azureId = $response->json('id');
        $this->link($user, $azureId);

        return [
            'status'   => 'created',
            'message'  => "Created {$upn} in Office 365. They sign in with the temporary password below and set their own.",
            'azure_id' => $azureId,
            'upn'      => $upn,
            /*
             * Returned once and never stored — the same rule the TOTP secret
             * follows. A temporary password kept in a column is a permanent
             * password to whoever reads the column.
             */
            'temporary_password' => $password,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Office 365 → HRMS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Directory accounts that no login here explains.
     *
     * Members only: guests are somebody else's staff, and inviting them onto
     * our org chart is not a suggestion worth making. Service accounts are
     * not filtered — they are real accounts somebody should look at, and
     * guessing which are which from a display name would hide the ones that
     * matter.
     *
     * @return array<int, array>
     */
    public function unmatched(int $organizationId, int $limit = 200): array
    {
        if (! $this->connection) {
            throw new RuntimeException('No Office 365 connection is configured for this organisation.');
        }

        $response = Http::withToken($this->token())->timeout(30)->acceptJson()
            ->get(self::GRAPH . '/users', [
                '$select' => 'id,displayName,userPrincipalName,mail,jobTitle,department,mobilePhone,officeLocation,accountEnabled,userType',
                '$top'    => min($limit, 999),
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Could not read the directory: ' . ($response->json('error.message') ?? $response->body())
            );
        }

        // Matched by object id OR by address: an account linked before
        // `azure_id` existed is still matched, and re-suggesting it would ask
        // somebody to create a duplicate of a person already here.
        $knownIds = User::query()->whereNotNull('azure_id')->pluck('azure_id')
            ->map(fn ($id) => strtolower((string) $id))->flip();

        $knownAddresses = User::query()->pluck('email')
            ->merge(Employee::query()->whereNotNull('work_email')->pluck('work_email'))
            ->filter()
            ->map(fn ($a) => strtolower(trim($a)))
            ->flip();

        return collect($response->json('value') ?? [])
            ->filter(fn (array $account) => ($account['userType'] ?? 'Member') === 'Member')
            ->reject(function (array $account) use ($knownIds, $knownAddresses) {
                if (isset($knownIds[strtolower($account['id'] ?? '')])) {
                    return true;
                }

                foreach ([$account['userPrincipalName'] ?? null, $account['mail'] ?? null] as $address) {
                    if ($address && isset($knownAddresses[strtolower($address)])) {
                        return true;
                    }
                }

                return false;
            })
            ->map(fn (array $account) => [
                'azure_id'        => $account['id'],
                'display_name'    => $account['displayName'] ?? null,
                'upn'             => $account['userPrincipalName'] ?? null,
                'mail'            => $account['mail'] ?? null,
                'job_title'       => $account['jobTitle'] ?? null,
                'department'      => $account['department'] ?? null,
                'mobile_phone'    => $account['mobilePhone'] ?? null,
                'office_location' => $account['officeLocation'] ?? null,
                'enabled'         => (bool) ($account['accountEnabled'] ?? true),
                // Split here rather than in the browser: the client would have
                // to guess at "Jane" vs "van der Berg" the same way, and doing
                // it once means the suggestion and the created record agree.
                'suggested'       => $this->splitName($account['displayName'] ?? ''),
            ])
            ->values()
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────

    /** Record the link, and move them onto the sign-in path that now exists. */
    private function link(User $user, ?string $azureId): void
    {
        if (! $azureId) {
            return;
        }

        // The same fields discovery sets when it finds somebody, so a
        // provisioned account and a discovered one are indistinguishable
        // afterwards — which is correct, because they are.
        $user->forceFill([
            'azure_id'          => $azureId,
            'auth_method'       => User::AUTH_SSO,
            'sso_only'          => true,
            'discovery_status'  => User::DISCOVERY_ENABLED,
            'account_enabled'   => true,
            'last_discovery_at' => now(),
            'discovery_error'   => null,
        ])->save();
    }

    private function already(string $azureId, ?string $upn, string $message): array
    {
        return [
            'status'   => 'already_exists',
            'message'  => $message,
            'azure_id' => $azureId,
            'upn'      => $upn,
            'temporary_password' => null,
        ];
    }

    /** @return array{id:string, userPrincipalName?:string}|null */
    private function findInDirectory(string $upn): ?array
    {
        $direct = Http::withToken($this->token())->timeout(15)->acceptJson()
            ->get(self::GRAPH . '/users/' . rawurlencode($upn), ['$select' => 'id,userPrincipalName']);

        if ($direct->successful()) {
            return $direct->json();
        }

        // A second-domain or migrated account answers 404 on the direct path
        // while existing perfectly well, so the mail attribute is asked too.
        $search = Http::withToken($this->token())->timeout(15)->acceptJson()
            ->withHeaders(['ConsistencyLevel' => 'eventual'])
            ->get(self::GRAPH . '/users', [
                '$filter' => "mail eq '" . str_replace("'", "''", $upn) . "'",
                '$select' => 'id,userPrincipalName',
                '$count'  => 'true',
            ]);

        $matches = $search->successful() ? ($search->json('value') ?? []) : [];

        // Exactly one, or nothing. More than one match is not an identity —
        // guessing which is the person would link the wrong account.
        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * The two-letter code licensing needs.
     *
     * `employees.country` is already varchar(3) holding an ISO code, so this
     * is a pass-through with a guard rather than a lookup: anything that is
     * not two letters falls back rather than being sent, because a usage
     * location Graph rejects fails the whole creation, and a wrong one
     * decides which services a licence may legally include.
     */
    private function usageLocationFor(?Employee $employee): string
    {
        $country = strtoupper(trim((string) $employee?->country));

        return preg_match('/^[A-Z]{2}$/', $country) ? $country : 'KE';
    }

    private function mailNickname(string $upn): string
    {
        return Str::of(Str::before($upn, '@'))
            ->replaceMatches('/[^A-Za-z0-9._-]/', '')
            ->limit(64, '')
            ->toString() ?: 'user' . Str::random(6);
    }

    /**
     * A password nobody keeps.
     *
     * Long and mixed because it has to satisfy whatever complexity policy the
     * tenant enforces, and it is used exactly once — the account is created
     * with forceChangePasswordNextSignIn, so its only job is to survive the
     * first sign-in.
     */
    private function temporaryPassword(): string
    {
        return Str::upper(Str::random(4)) . Str::lower(Str::random(6))
            . random_int(100, 999) . '!' . Str::random(4);
    }

    /** @return array{first_name:string, last_name:?string} */
    private function splitName(string $displayName): array
    {
        $parts = preg_split('/\s+/', trim($displayName)) ?: [];

        return [
            'first_name' => $parts[0] ?? '',
            'last_name'  => count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : null,
        ];
    }

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
