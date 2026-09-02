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
 * Taking somebody's access away.
 *
 * Four things have to happen, and the order matters. Doing them in the wrong
 * one leaves a window in which the person is "disabled" and still working:
 *
 *  1. Disable the account in Entra, so no new sign-in succeeds anywhere.
 *  2. Revoke their Entra refresh tokens, because step 1 does **not** invalidate
 *     tokens already issued — an access token stays good for up to an hour, and
 *     without this the mailbox they were reading stays open until it expires.
 *  3. Mark the account disabled here.
 *  4. Delete their API tokens here, for the same reason as step 2.
 *
 * Reversible on purpose. A leaver is sometimes a leaver by mistake, and the
 * opposite of this has to be one action rather than a support ticket. What it
 * does not restore is the sessions — those are gone, which is correct.
 *
 * The Office 365 half needs `User.ReadWrite.All`, a wider grant than the
 * `User.Read.All` discovery uses. Where the tenant has not granted it, the
 * local half still happens and the caller is told plainly which half did not —
 * a partial result reported is far better than an all-or-nothing that silently
 * leaves somebody signed in.
 */
class Deprovisioner
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    public function __construct(private readonly ?IntegrationConnection $connection)
    {
    }

    public static function forOrganization(int $organizationId): self
    {
        $org = Organization::find($organizationId);

        $connection = $org?->identity_connection_id
            ? IntegrationConnection::where('id', $org->identity_connection_id)
                ->where('is_active', true)->first()
            : null;

        // Null is allowed: somebody with no Microsoft account still has to be
        // deprovisionable, and so does everybody when the tenant is unreachable.
        return new self($connection);
    }

    /**
     * @return array{local: bool, directory: string, message: string}
     */
    public function disable(User $user, ?User $actor = null): array
    {
        if ($actor && $actor->id === $user->id) {
            throw new RuntimeException(
                'You cannot deprovision your own account — you would be locking yourself out mid-action.',
            );
        }

        $directory = $this->setEntraEnabled($user, false);

        $user->forceFill([
            'account_status'    => User::STATUS_DISABLED,
            'status_changed_at' => now(),
        ])->save();

        // Sessions here, gone now rather than whenever they expire.
        $user->tokens()->delete();

        return [
            'local'     => true,
            'directory' => $directory['status'],
            'message'   => $this->explain($user, $directory, disabled: true),
        ];
    }

    /**
     * Put them back.
     *
     * `pending` rather than `active`: restoring access is not the same as
     * asserting the account is in good order, and this tenant uses `pending`
     * for exactly that — imported, allowed in, not vouched for.
     *
     * @return array{local: bool, directory: string, message: string}
     */
    public function restore(User $user): array
    {
        $directory = $this->setEntraEnabled($user, true);

        $user->forceFill([
            'account_status'    => User::STATUS_PENDING,
            'status_changed_at' => now(),
        ])->save();

        return [
            'local'     => true,
            'directory' => $directory['status'],
            'message'   => $this->explain($user, $directory, disabled: false),
        ];
    }

    /**
     * Flip `accountEnabled` in Entra, and kill the tokens already out there.
     *
     * Keyed on `azure_id`, the immutable object id, never the address — an
     * address can be reassigned to somebody else, and disabling the wrong
     * person is not a mistake that announces itself.
     *
     * @return array{status: string, detail: string|null}
     */
    private function setEntraEnabled(User $user, bool $enabled): array
    {
        if (! $user->azure_id) {
            return ['status' => 'not_in_directory', 'detail' => null];
        }

        if (! $this->connection) {
            return ['status' => 'no_connection', 'detail' => null];
        }

        $patch = Http::withToken($this->token())->timeout(20)->acceptJson()
            ->patch(self::GRAPH . '/users/' . rawurlencode($user->azure_id), [
                'accountEnabled' => $enabled,
            ]);

        if ($patch->failed()) {
            Log::warning('Entra account state change failed', [
                'user' => $user->id, 'enabled' => $enabled,
                'status' => $patch->status(), 'body' => $patch->body(),
            ]);

            return [
                'status' => 'failed',
                'detail' => $patch->status() === 403
                    ? "Microsoft refused it. Disabling an account needs 'User.ReadWrite.All' with admin "
                        . 'consent — discovery only needs the read-only grant.'
                    : (string) ($patch->json('error.message') ?? $patch->body()),
            ];
        }

        /*
         * Disabling does not invalidate tokens already issued: an access token
         * stays good for up to an hour, so without this the person keeps
         * reading mail after being "disabled". Only on the way out — restoring
         * somebody has no sessions to revoke.
         */
        if (! $enabled) {
            $revoke = Http::withToken($this->token())->timeout(20)->acceptJson()
                ->post(self::GRAPH . '/users/' . rawurlencode($user->azure_id) . '/revokeSignInSessions');

            if ($revoke->failed()) {
                return [
                    'status' => 'disabled_sessions_kept',
                    'detail' => 'The account is disabled, but Microsoft refused to revoke the sessions it '
                        . 'has already issued. Those expire within the hour.',
                ];
            }
        }

        return ['status' => $enabled ? 'enabled' : 'disabled', 'detail' => null];
    }

    /** Say exactly which halves happened, in one sentence somebody can act on. */
    private function explain(User $user, array $directory, bool $disabled): string
    {
        $here = $disabled
            ? "{$user->email} can no longer sign in here and their sessions are closed."
            : "{$user->email} can sign in here again.";

        return $here . ' ' . match ($directory['status']) {
            'disabled'                => 'Their Office 365 account is disabled and its sessions revoked.',
            'enabled'                 => 'Their Office 365 account is enabled again.',
            'disabled_sessions_kept'  => $directory['detail'],
            'not_in_directory'        => 'They have no Office 365 account on record, so nothing was changed there.',
            'no_connection'           => 'No directory connection is configured, so Office 365 was not changed.',
            'failed'                  => 'Office 365 was NOT changed: ' . $directory['detail'],
            default                   => '',
        };
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
