<?php

namespace App\Services\Recruitment;

use App\Models\Recruitment\Interview;
use App\Models\Recruitment\InterviewPanelist;
use App\Services\Integration\CalendarConnectionResolver;
use App\Services\Integration\TeamsMeetingClient;
use RuntimeException;

/**
 * Interview scheduling's own face on the shared Teams meeting client.
 *
 * The Graph calls themselves live in TeamsMeetingClient, shared with Support's
 * ticket-call scheduling — this class only knows how to turn an Interview
 * into the generic event shape that client expects.
 */
class TeamsService
{
    public function __construct(
        private readonly TeamsMeetingClient $client,
        private readonly string $organizerMailbox,
    ) {}

    /**
     * Built from the org's chosen calendar connection.
     *
     * Throws rather than falling back to anything — a silent fallback here is
     * exactly the "which connection, when there could be several" ambiguity
     * this replaced.
     */
    public static function forOrganization(int $organizationId): self
    {
        $connection = CalendarConnectionResolver::resolve($organizationId);

        $mailbox = env('HR_CALENDAR_MAILBOX', env('MAIL_FROM_ADDRESS', ''));
        if (! $mailbox) {
            throw new RuntimeException('HR_CALENDAR_MAILBOX is not configured. Cannot create Teams events.');
        }

        return new self(new TeamsMeetingClient($connection), $mailbox);
    }

    // ── Create or update a Teams calendar event ───────────────────────────

    public function createOrUpdateEvent(Interview $interview, array $attendeeEmails, string $subject, string $bodyHtml): array
    {
        $data = [
            'subject'   => $subject,
            'body_html' => $bodyHtml,
            'start'     => $interview->scheduled_at,
            'end'       => $interview->scheduled_at->copy()->addMinutes($interview->duration_minutes),
            'attendees' => collect($attendeeEmails)->map(fn ($email) => ['name' => null, 'address' => $email])->values()->all(),
        ];

        if (! $interview->teams_event_id) {
            return $this->client->createEvent($this->organizerMailbox, $data);
        }

        $result = $this->client->updateEvent($this->organizerMailbox, $interview->teams_event_id, $data);

        // Graph's PATCH response doesn't always echo the online-meeting block
        // back — keep the join link this interview already had rather than
        // let a successful reschedule blank it out.
        $result['join_url'] ??= $interview->teams_join_url;

        return $result;
    }

    // ── Delete a Teams event ──────────────────────────────────────────────

    public function deleteEvent(string $eventId): void
    {
        $this->client->cancelEvent($this->organizerMailbox, $eventId);
    }

    // ── Issue per-panelist scoring token ──────────────────────────────────

    public static function issueScoringToken(InterviewPanelist $panelist, int $daysValid = 30): string
    {
        $token = bin2hex(random_bytes(32));

        // Only the hash is stored. A read of this column — a backup, a support
        // query, a leak — yields no working link, the same treatment portal
        // and survey tokens already get. The plaintext is returned once, here,
        // to be put in the URL and never persisted.
        $panelist->update([
            'scoring_token'            => hash('sha256', $token),
            'scoring_token_expires_at' => now()->addDays($daysValid),
        ]);
        return $token;
    }

    // ── Scoring form public URL ───────────────────────────────────────────

    public static function scoringUrl(string $token): string
    {
        $frontendBase = rtrim(env('FRONTEND_URL', config('app.url')), '/');
        return "{$frontendBase}/#/score/{$token}";
    }
}
