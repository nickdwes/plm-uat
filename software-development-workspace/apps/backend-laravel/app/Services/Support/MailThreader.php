<?php

namespace App\Services\Support;

use App\Models\Support\Ticket;
use App\Models\Support\TicketEmail;

/**
 * Deciding which ticket an incoming message belongs to.
 *
 * ── Why this is its own class ──
 *
 * The old module did it in one line:
 *
 *     load(array('message_id = ? or subject = ? or subject = ?', ...))
 *
 * Two customers who both wrote "Internet down" landed on the same ticket and
 * read each other's correspondence. That is a confidentiality failure, not a
 * data-quality one, and it is the single most important thing this rebuild has
 * to get right — mail is how essentially every ticket arrives.
 *
 * ── The order ──
 *
 *   1. In-Reply-To against message identifiers we have actually seen
 *   2. Anything in References, likewise
 *   3. A [SD-…] reference in the subject — and only if the sender is already
 *      party to that ticket
 *   4. Otherwise it is new
 *
 * Steps one and two are RFC 5322 threading and are evidence. Step three is a
 * convenience for mail clients that drop headers, and it is guarded: a
 * reference quoted by a stranger proves nothing, and without the guard anyone
 * who learned a ticket number could read the thread by writing to it.
 */
class MailThreader
{
    /** @param array $message normalised inbound message */
    public function match(int $organizationId, array $message): ?Ticket
    {
        $byHeaders = $this->byHeaders($organizationId, $message);

        if ($byHeaders) {
            return $byHeaders;
        }

        return $this->bySubjectReference($organizationId, $message);
    }

    /** Threading on identifiers this system has actually seen before. */
    private function byHeaders(int $organizationId, array $message): ?Ticket
    {
        $candidates = array_filter(array_merge(
            [$message['in_reply_to'] ?? null],
            $this->parseReferences($message['references'] ?? null),
        ));

        if ($candidates === []) {
            return null;
        }

        $email = TicketEmail::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->whereNotNull('support_ticket_id')
            ->whereIn('internet_message_id', $candidates)
            ->orderByDesc('id')
            ->first();

        return $email?->ticket;
    }

    /**
     * A [SD-2026-00001] token in the subject, honoured only for somebody
     * already on the ticket.
     *
     * The reference travels in every reply we send, so a customer's mail client
     * quotes it back even when it has mangled the headers. It is also visible
     * to anyone who has ever been forwarded one of our emails, which is exactly
     * why the sender check is not optional.
     */
    private function bySubjectReference(int $organizationId, array $message): ?Ticket
    {
        if (! preg_match('/\[(SD-\d{4}-\d{5})\]/i', (string) ($message['subject'] ?? ''), $m)) {
            return null;
        }

        $ticket = Ticket::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('reference', strtoupper($m[1]))
            ->first();

        if (! $ticket) {
            return null;
        }

        return $this->isPartyTo($ticket, $message['from_email'] ?? null) ? $ticket : null;
    }

    /**
     * Has this address been on this ticket before?
     *
     * The requester, or anyone who was a recipient on a message belonging to
     * it. A colleague who was copied on the original replies from their own
     * address and should land on the same ticket; a stranger quoting the
     * reference should not.
     */
    public function isPartyTo(Ticket $ticket, ?string $address): bool
    {
        $address = mb_strtolower(trim((string) $address));

        if ($address === '') {
            return false;
        }

        if (mb_strtolower((string) $ticket->requester_email) === $address) {
            return true;
        }

        $emailIds = TicketEmail::withoutGlobalScope('organization')
            ->where('support_ticket_id', $ticket->id)
            ->pluck('id');

        if ($emailIds->isEmpty()) {
            return false;
        }

        $seenAsSender = TicketEmail::withoutGlobalScope('organization')
            ->where('support_ticket_id', $ticket->id)
            ->whereRaw('lower(from_email) = ?', [$address])
            ->exists();

        if ($seenAsSender) {
            return true;
        }

        return \App\Models\Support\TicketEmailRecipient::whereIn('support_ticket_email_id', $emailIds)
            ->whereRaw('lower(address) = ?', [$address])
            ->exists();
    }

    /** References is a space-separated list of angle-bracketed identifiers. */
    private function parseReferences(?string $references): array
    {
        if (blank($references)) {
            return [];
        }

        preg_match_all('/<[^>]+>/', $references, $matches);

        return $matches[0] ?? [];
    }
}
