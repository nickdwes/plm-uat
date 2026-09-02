<?php

namespace App\Services\Integration;

use App\Models\Integration\IntegrationConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * One Teams meeting, created on a calendar via Microsoft Graph.
 *
 * A calendar event with `isOnlineMeeting: true` is Graph's own shortcut for
 * "make this a Teams call" — it needs only `Calendars.ReadWrite`, not the
 * separate online-meetings API, and Graph hands back a join URL in the same
 * response. Split out of Recruitment's TeamsService so a second caller
 * (Support) doesn't re-implement the same HTTP calls against an Interview-
 * shaped payload it doesn't have.
 */
class TeamsMeetingClient
{
    public function __construct(private readonly IntegrationConnection $connection) {}

    private function token(): string
    {
        $key = 'ms_graph_calendar_token_' . $this->connection->id;
        return Cache::remember($key, 3500, function (): string {
            $res = Http::asForm()->timeout(10)->post(
                "https://login.microsoftonline.com/{$this->connection->tenant_id}/oauth2/v2.0/token",
                [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $this->connection->client_id,
                    'client_secret' => $this->connection->client_secret,
                    'scope'         => 'https://graph.microsoft.com/.default',
                ],
            );
            if ($res->failed()) {
                throw new RuntimeException('Teams token request failed: ' . $res->body());
            }
            return $res->json('access_token');
        });
    }

    /**
     * @param  array{subject: string, body_html: string, start: \Illuminate\Support\Carbon,
     *               end: \Illuminate\Support\Carbon, attendees: array<int, array{name: ?string, address: string}>,
     *               time_zone?: string} $data
     * @return array{event_id: string, join_url: ?string}
     */
    public function createEvent(string $organizerMailbox, array $data): array
    {
        $payload = [
            'subject'      => $data['subject'],
            'body'         => ['contentType' => 'HTML', 'content' => $data['body_html']],
            'start'        => ['dateTime' => $data['start']->toIso8601String(), 'timeZone' => $data['time_zone'] ?? 'Africa/Nairobi'],
            'end'          => ['dateTime' => $data['end']->toIso8601String(), 'timeZone' => $data['time_zone'] ?? 'Africa/Nairobi'],
            'isOnlineMeeting'       => true,
            'onlineMeetingProvider' => 'teamsForBusiness',
            'allowNewTimeProposals' => false,
            'attendees' => collect($data['attendees'])->map(fn ($a) => [
                'emailAddress' => array_filter(['address' => $a['address'], 'name' => $a['name'] ?? null]),
                'type'         => 'required',
            ])->values()->all(),
        ];

        $res = Http::withToken($this->token())->timeout(20)->post(
            "https://graph.microsoft.com/v1.0/users/{$organizerMailbox}/events",
            $payload,
        );

        if ($res->failed()) {
            $message = $res->json('error.message') ?? $res->body();
            Log::warning('Teams meeting creation failed', ['status' => $res->status(), 'body' => $res->body()]);

            if ($res->status() === 403) {
                throw new RuntimeException(
                    "Access denied by Microsoft Graph. Ensure the calendar connection's app has 'Calendars.ReadWrite' " .
                    "granted with admin consent, and that {$organizerMailbox} is a real mailbox in this tenant.",
                );
            }

            throw new RuntimeException($message);
        }

        $body = $res->json();

        return [
            'event_id' => $body['id'],
            'join_url' => $body['onlineMeeting']['joinUrl'] ?? $body['webLink'] ?? null,
        ];
    }

    /**
     * @param  array{subject: string, body_html: string, start: \Illuminate\Support\Carbon,
     *               end: \Illuminate\Support\Carbon, attendees: array<int, array{name: ?string, address: string}>,
     *               time_zone?: string} $data
     * @return array{event_id: string, join_url: ?string}
     */
    public function updateEvent(string $organizerMailbox, string $eventId, array $data): array
    {
        $payload = [
            'subject'      => $data['subject'],
            'body'         => ['contentType' => 'HTML', 'content' => $data['body_html']],
            'start'        => ['dateTime' => $data['start']->toIso8601String(), 'timeZone' => $data['time_zone'] ?? 'Africa/Nairobi'],
            'end'          => ['dateTime' => $data['end']->toIso8601String(), 'timeZone' => $data['time_zone'] ?? 'Africa/Nairobi'],
            'isOnlineMeeting'       => true,
            'onlineMeetingProvider' => 'teamsForBusiness',
            'allowNewTimeProposals' => false,
            'attendees' => collect($data['attendees'])->map(fn ($a) => [
                'emailAddress' => array_filter(['address' => $a['address'], 'name' => $a['name'] ?? null]),
                'type'         => 'required',
            ])->values()->all(),
        ];

        $res = Http::withToken($this->token())->timeout(20)->patch(
            "https://graph.microsoft.com/v1.0/users/{$organizerMailbox}/events/{$eventId}",
            $payload,
        );

        if ($res->failed()) {
            $message = $res->json('error.message') ?? $res->body();
            Log::warning('Teams meeting update failed', ['status' => $res->status(), 'body' => $res->body()]);
            throw new RuntimeException($message);
        }

        $body = $res->json();

        return [
            'event_id' => $eventId,
            'join_url' => $body['onlineMeeting']['joinUrl'] ?? $body['webLink'] ?? null,
        ];
    }

    public function cancelEvent(string $organizerMailbox, string $eventId): void
    {
        try {
            Http::withToken($this->token())->timeout(10)->delete(
                "https://graph.microsoft.com/v1.0/users/{$organizerMailbox}/events/{$eventId}",
            );
        } catch (\Throwable $e) {
            Log::warning('Teams meeting cancel failed', ['error' => $e->getMessage()]);
        }
    }

    // ── Recording & transcript ──────────────────────────────────────────────
    //
    // A calendar event and its online meeting are two different Graph objects;
    // recordings and transcripts hang off the online meeting, which we resolve
    // from the join URL. These calls need OnlineMeetings.Read.All plus
    // OnlineMeetingRecording.Read.All / OnlineMeetingTranscript.Read.All granted
    // with admin consent — a 403 here means the app has the calendar scope but
    // not the meeting-artifact ones.

    /**
     * The organiser's object id (GUID). The online-meetings API, unlike the
     * calendar one, will not accept a mailbox address in the URL under
     * application permissions — it wants the user's id.
     */
    public function resolveUserId(string $mailbox): string
    {
        $res = Http::withToken($this->token())->timeout(15)->get(
            "https://graph.microsoft.com/v1.0/users/{$mailbox}",
            ['$select' => 'id'],
        );

        $this->guardArtifactResponse($res, 'resolve the organiser mailbox');

        $id = $res->json('id');
        if (! $id) {
            throw new RuntimeException("Could not resolve the organiser mailbox {$mailbox} to a user.");
        }

        return $id;
    }

    /** The online-meeting id for a join URL, or null if Graph has none yet. */
    public function onlineMeetingId(string $organizerId, string $joinUrl): ?string
    {
        $res = Http::withToken($this->token())->timeout(20)->get(
            "https://graph.microsoft.com/v1.0/users/{$organizerId}/onlineMeetings",
            ['$filter' => "JoinWebUrl eq '{$joinUrl}'"],
        );

        $this->guardArtifactResponse($res, 'resolve the online meeting');

        return $res->json('value.0.id');
    }

    /** @return array<int, array<string, mixed>> the callRecording objects. */
    public function recordings(string $organizerId, string $meetingId): array
    {
        $res = Http::withToken($this->token())->timeout(20)->get(
            "https://graph.microsoft.com/v1.0/users/{$organizerId}/onlineMeetings/{$meetingId}/recordings",
        );

        $this->guardArtifactResponse($res, 'list recordings');

        return $res->json('value') ?? [];
    }

    /** @return array<int, array<string, mixed>> the callTranscript objects. */
    public function transcripts(string $organizerId, string $meetingId): array
    {
        $res = Http::withToken($this->token())->timeout(20)->get(
            "https://graph.microsoft.com/v1.0/users/{$organizerId}/onlineMeetings/{$meetingId}/transcripts",
        );

        $this->guardArtifactResponse($res, 'list transcripts');

        return $res->json('value') ?? [];
    }

    /** The transcript text (WebVTT), or null if it could not be fetched. */
    public function transcriptContent(string $organizerId, string $meetingId, string $transcriptId): ?string
    {
        $res = Http::withToken($this->token())->timeout(30)->get(
            "https://graph.microsoft.com/v1.0/users/{$organizerId}/onlineMeetings/{$meetingId}/transcripts/{$transcriptId}/content",
            ['$format' => 'text/vtt'],
        );

        if ($res->failed()) {
            Log::warning('Teams transcript content fetch failed', ['status' => $res->status()]);
            return null;
        }

        return $res->body() ?: null;
    }

    private function guardArtifactResponse(\Illuminate\Http\Client\Response $res, string $action): void
    {
        if (! $res->failed()) {
            return;
        }

        Log::warning("Teams meeting artifact fetch failed ({$action})", ['status' => $res->status(), 'body' => $res->body()]);

        if ($res->status() === 403) {
            throw new RuntimeException(
                "Access denied by Microsoft Graph while trying to {$action}. The app needs the meeting-artifact scopes " .
                "granted with admin consent ('OnlineMeetings.Read.All', 'OnlineMeetingRecording.Read.All', " .
                "'OnlineMeetingTranscript.Read.All') AND — for app-only access — a Teams application access policy " .
                "authorising it to read these meetings (New-CsApplicationAccessPolicy / Grant-CsApplicationAccessPolicy " .
                'for the calendar app id). Granting the scope alone is not enough for app-only online-meeting access.',
            );
        }

        throw new RuntimeException($res->json('error.message') ?? "Could not {$action}.");
    }
}
