<?php

namespace App\Services\Support;

use App\Models\Account\AccountContact;
use App\Models\Support\SenderRule;

/**
 * Whether a stranger may open a ticket by writing in.
 *
 * The old module asked this question, loaded the answer, and never looked at
 * it — so any address on earth could open a ticket and receive an auto-reply
 * carrying the company's name. That is a spam amplifier with a support desk
 * attached.
 *
 * ── Quarantine rather than refuse ──
 *
 * Unrecognised mail is held, not discarded and not bounced. Discarding means
 * the message a customer swears they sent cannot be produced; bouncing tells a
 * spammer the address is live. Held mail sits in a list somebody can release in
 * one click, which is also how the whitelist gets populated in practice.
 */
class SenderPolicy
{
    public const ALLOW      = 'allow';
    public const BLOCK      = 'block';
    public const QUARANTINE = 'quarantine';

    /** @return array{decision: string, reason: ?string} */
    public function decide(int $organizationId, ?string $address): array
    {
        $address = mb_strtolower(trim((string) $address));

        if ($address === '' || ! str_contains($address, '@')) {
            return ['decision' => self::QUARANTINE, 'reason' => 'No usable sender address'];
        }

        $domain = substr(strrchr($address, '@'), 1);

        $rules = SenderRule::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where(function ($q) use ($address, $domain) {
                $q->where(fn ($r) => $r->where('kind', SenderRule::KIND_ADDRESS)->where('value', $address))
                  ->orWhere(fn ($r) => $r->where('kind', SenderRule::KIND_DOMAIN)->where('value', $domain));
            })
            ->get();

        /*
         * Block wins over allow, and the more specific rule is not given
         * precedence deliberately: somebody blocking an address wants it
         * blocked, even if its domain is on the allow list.
         */
        if ($rules->firstWhere('action', SenderRule::ACTION_BLOCK)) {
            return ['decision' => self::BLOCK, 'reason' => 'Sender is blocked'];
        }

        if ($rules->firstWhere('action', SenderRule::ACTION_ALLOW)) {
            return ['decision' => self::ALLOW, 'reason' => null];
        }

        /*
         * Somebody already recorded as a contact at a client account is a
         * customer whether or not anybody has written a rule. This is what
         * makes the policy usable from the first day rather than after
         * somebody remembers to fill in a whitelist — the old table had a
         * check nobody wrote and no data in it either.
         */
        $isContact = AccountContact::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->whereRaw('lower(email) = ?', [$address])
            ->exists();

        if ($isContact) {
            return ['decision' => self::ALLOW, 'reason' => null];
        }

        return [
            'decision' => self::QUARANTINE,
            'reason'   => "Sender {$address} is not a known contact and no rule allows {$domain}",
        ];
    }
}
