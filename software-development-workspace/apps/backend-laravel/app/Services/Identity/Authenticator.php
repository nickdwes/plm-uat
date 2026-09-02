<?php

namespace App\Services\Identity;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use OTPHP\TOTP;
use RuntimeException;

/**
 * The local second factor: RFC 6238 time-based codes.
 *
 * The algorithm comes from `spomky-labs/otphp` rather than from here. Writing
 * the HMAC by hand is the classic way to ship something that looks correct and
 * accepts codes it should not — the counter arithmetic, the dynamic truncation
 * and the base32 alphabet all have edge cases, and none of them announce
 * themselves when they are wrong.
 *
 * What this class is responsible for is everything around the algorithm, which
 * is where the actual weaknesses live:
 *
 * - **Confirm before activate.** A secret exists from the moment the QR is
 *   drawn. It must not authenticate anybody until they have read a code back,
 *   or an enrolment somebody abandoned half-way leaves an account reachable by
 *   a secret nobody holds.
 * - **One-time really means once.** The same six digits stay valid for a whole
 *   step, and with a ±1 window for up to ninety seconds. The accepted step is
 *   recorded and anything at or below it refused.
 * - **Throttled.** Six digits is a million possibilities, which only costs an
 *   attacker anything if they are made to wait.
 * - **Constant-time comparison**, so the failure does not leak how nearly
 *   right the guess was. `otphp` compares this way internally; the recovery
 *   codes are hashed, which gives the same property.
 * - **Recovery codes are passwords that work once**, so they are hashed like
 *   passwords and consumed on use.
 */
class Authenticator
{
    /** Thirty seconds, six digits, SHA-1: what every authenticator app expects. */
    private const PERIOD    = 30;
    private const DIGITS    = 6;
    private const ALGORITHM = 'sha1';

    /**
     * How far a phone's clock may drift, in seconds.
     *
     * `otphp` takes a leeway in seconds and requires it to be **under** the
     * period, so this is one step minus a second rather than a step count —
     * near-enough ±1 window without tripping the library's own guard.
     *
     * Kept as tight as the library allows: every extra second is another
     * second during which a stolen code still works.
     */
    private const LEEWAY = self::PERIOD - 1;

    private const MAX_FAILURES  = 5;
    private const LOCK_MINUTES  = 15;
    private const RECOVERY_CODES = 8;

    /**
     * Start enrolling somebody.
     *
     * Returns the secret and the `otpauth://` URI to draw as a QR. The secret
     * is stored but **not** confirmed, so nothing can be signed in with it yet.
     *
     * Re-issuing replaces the old secret, which is why the caller has to mean
     * it: anyone already enrolled loses their app registration.
     *
     * @return array{secret: string, uri: string}
     */
    public function beginEnrollment(User $user, string $issuer = 'KNS'): array
    {
        $totp = TOTP::generate();
        $totp->setPeriod(self::PERIOD);
        $totp->setDigits(self::DIGITS);
        $totp->setDigest(self::ALGORITHM);
        $totp->setLabel($user->alt_username ?: $user->email);
        $totp->setIssuer($issuer);

        $user->forceFill([
            'totp_secret'       => $totp->getSecret(),
            'totp_confirmed_at' => null,
            'totp_last_step'    => null,
            'totp_failures'     => 0,
            'totp_locked_until' => null,
            // Re-enrolling invalidates the old codes: they were issued against
            // a secret that no longer exists.
            'recovery_codes'    => null,
        ])->save();

        return ['secret' => $totp->getSecret(), 'uri' => $totp->getProvisioningUri()];
    }

    /**
     * Prove the app was set up, and turn it on.
     *
     * The confirming code is checked exactly as a sign-in code would be —
     * same window, same replay rule — so an enrolment cannot be completed with
     * something a sign-in would refuse.
     *
     * A consequence worth knowing: the code that confirms enrolment is spent
     * by confirming, so it will not also sign the person in. They wait for the
     * next one, which is thirty seconds and the correct answer anyway — a code
     * that both proved the app works and opened a session would be a code used
     * twice.
     *
     * @return list<string> the recovery codes, shown once and never again
     */
    public function confirmEnrollment(User $user, string $code): array
    {
        if ($user->totp_secret === null) {
            throw new RuntimeException('There is no enrolment in progress for this person.');
        }

        if (! $this->accept($user, $code)) {
            throw new RuntimeException('That code did not match. Check the phone\'s clock and try the current code.');
        }

        $plain = [];
        $hashed = [];

        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            // Ambiguity-free alphabet: a recovery code is read off paper under
            // pressure, and 0/O and 1/l cost support calls.
            $code = strtoupper(bin2hex(random_bytes(5)));
            $plain[] = implode('-', str_split($code, 5));
            $hashed[] = Hash::make(end($plain));
        }

        $user->forceFill([
            'totp_confirmed_at' => now(),
            'recovery_codes'    => $hashed,
            'totp_failures'     => 0,
            'totp_locked_until' => null,
        ])->save();

        return $plain;
    }

    /**
     * Check a code at sign-in.
     *
     * Returns false rather than throwing for a wrong code — a wrong code is an
     * ordinary event — but throws when the account is locked, because that is
     * a different answer and the caller has to say so differently.
     */
    public function verify(User $user, string $code): bool
    {
        if (! $user->hasConfirmedAuthenticator()) {
            return false;
        }

        if ($user->totp_locked_until && $user->totp_locked_until->isFuture()) {
            throw new RuntimeException(sprintf(
                'Too many wrong codes. Try again in %d minute(s).',
                max(1, (int) ceil(now()->diffInMinutes($user->totp_locked_until, false))),
            ));
        }

        if ($this->accept($user, $code)) {
            $user->forceFill(['totp_failures' => 0, 'totp_locked_until' => null])->save();

            return true;
        }

        $this->recordFailure($user);

        return false;
    }

    /**
     * Spend a recovery code.
     *
     * Hashed, so this walks the list — there are eight of them, and the cost
     * of eight hash comparisons is the price of not storing them readable.
     * Consumed on use: a code that still worked afterwards would be a password
     * on a piece of paper.
     */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->recovery_codes ?? [];
        $normalised = strtoupper(trim($code));

        foreach ($codes as $index => $hash) {
            if (Hash::check($normalised, $hash)) {
                unset($codes[$index]);

                $user->forceFill([
                    'recovery_codes'    => array_values($codes),
                    'totp_failures'     => 0,
                    'totp_locked_until' => null,
                ])->save();

                return true;
            }
        }

        $this->recordFailure($user);

        return false;
    }

    /**
     * Is this code valid, and has it not already been spent?
     *
     * The two halves are inseparable: `otphp` will happily verify the same
     * code twice inside its window, which is correct for the algorithm and
     * wrong for an authentication.
     */
    private function accept(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }

        $totp = TOTP::createFromSecret($user->totp_secret);
        $totp->setPeriod(self::PERIOD);
        $totp->setDigits(self::DIGITS);
        $totp->setDigest(self::ALGORITHM);

        if (! $totp->verify($code, null, self::LEEWAY)) {
            return false;
        }

        $step = intdiv(now()->getTimestamp(), self::PERIOD);

        /*
         * Replay. The accepted step is not necessarily the current one — the
         * window allows the previous — so anything at or below the last one we
         * honoured is refused, which also covers a code being presented twice
         * inside its own step.
         */
        if ($user->totp_last_step !== null && $step <= $user->totp_last_step) {
            return false;
        }

        $user->forceFill(['totp_last_step' => $step])->save();

        return true;
    }

    /** Count a wrong answer, and stop counting past the point of locking. */
    private function recordFailure(User $user): void
    {
        $failures = (int) $user->totp_failures + 1;

        $user->forceFill([
            'totp_failures'     => $failures,
            'totp_locked_until' => $failures >= self::MAX_FAILURES
                ? now()->addMinutes(self::LOCK_MINUTES)
                : $user->totp_locked_until,
        ])->save();
    }
}
