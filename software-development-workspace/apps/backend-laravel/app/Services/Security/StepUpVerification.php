<?php

namespace App\Services\Security;

use App\Mail\StepUpCodeMail;
use App\Models\Security\SecurityStepUpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Proving it is really you, right now.
 *
 * A permission check answers "is this account allowed to see this" — it says
 * nothing about who is actually sitting at the keyboard. This answers the
 * second question: a six-digit code sent to the signed-in user's own address,
 * asked for immediately before something genuinely sensitive is shown, so a
 * stolen session cookie is not enough on its own.
 *
 * The code always goes to the viewer's own address, never the address of
 * whoever's record they are about to look at — an HR officer opening somebody
 * else's payroll proves it is themselves, not the other way round.
 */
class StepUpVerification
{
    private const CODE_LENGTH = 6;

    /** How long the emailed code is good for. */
    private const CODE_TTL_MINUTES = 10;

    /**
     * How long a successful verification stands afterwards.
     *
     * Long enough that HR moving between several employees' payroll does not
     * re-verify for every one — the proof is "it is you, right now", not
     * "you may look at this particular person" — short enough that stepping
     * away from the desk means the next viewer has to prove it again.
     */
    private const ELEVATION_MINUTES = 15;

    /** A code guessed five times over is a code somebody is guessing. */
    private const MAX_ATTEMPTS = 5;

    public function requestCode(User $user, string $purpose, ?string $ip, ?string $userAgent): void
    {
        $code = str_pad((string) random_int(0, (10 ** self::CODE_LENGTH) - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);

        SecurityStepUpCode::create([
            'user_id'    => $user->id,
            'purpose'    => $purpose,
            'code_hash'  => Hash::make($code),
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'ip_address' => $ip,
            'user_agent' => $userAgent ? Str::limit($userAgent, 250, '') : null,
        ]);

        Mail::to($user->email)->send(new StepUpCodeMail($code, $purpose, self::CODE_TTL_MINUTES));
    }

    /**
     * @return array{ok: bool, elevated_until: ?\Illuminate\Support\Carbon, message: ?string}
     */
    public function verify(User $user, string $purpose, string $code): array
    {
        $attempt = SecurityStepUpCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->where('expires_at', '>=', now())
            ->orderByDesc('id')
            ->first();

        if (! $attempt) {
            return ['ok' => false, 'elevated_until' => null,
                'message' => 'That code has expired. Request a new one.'];
        }

        if ($attempt->attempts >= self::MAX_ATTEMPTS) {
            return ['ok' => false, 'elevated_until' => null,
                'message' => 'Too many wrong attempts. Request a new code.'];
        }

        if (! Hash::check($code, $attempt->code_hash)) {
            $attempt->increment('attempts');

            return ['ok' => false, 'elevated_until' => null,
                'message' => 'That code is not right.'];
        }

        $elevatedUntil = now()->addMinutes(self::ELEVATION_MINUTES);

        $attempt->update(['verified_at' => now(), 'elevated_until' => $elevatedUntil]);

        return ['ok' => true, 'elevated_until' => $elevatedUntil, 'message' => null];
    }

    public function isElevated(User $user, string $purpose): bool
    {
        return SecurityStepUpCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('elevated_until', '>=', now())
            ->exists();
    }

    public function elevatedUntil(User $user, string $purpose): ?\Illuminate\Support\Carbon
    {
        return SecurityStepUpCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('elevated_until', '>=', now())
            ->orderByDesc('elevated_until')
            ->value('elevated_until');
    }
}
