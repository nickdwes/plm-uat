<?php

namespace App\Services\Recruitment;

use App\Models\Recruitment\Interview;
use App\Models\Recruitment\InterviewPanelist;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Microsoft Teams / Graph API service for interview calendar events.
 *
 * Required Azure app permissions (application type):
 *   - Calendars.ReadWrite  (to create Teams meetings on behalf of the organiser)
 *   - OnlineMeetings.ReadWrite.All (alternative for direct online meeting creation)
 *
 * The organiser mailbox is the HR_CALENDAR_MAILBOX env variable.
 */
class TeamsService
{
    private string $tenantId;
    private string $clientId;
    private string $clientSecret;
    private string $organizerMailbox;

    public function __construct()
    {
        $this->tenantId        = config('services.microsoft.tenant_id',  env('MICROSOFT_TENANT_ID'));
        $this->clientId        = config('services.microsoft.client_id',  env('MICROSOFT_CLIENT_ID'));
        $this->clientSecret    = config('services.microsoft.client_secret', env('MICROSOFT_CLIENT_SECRET'));
        $this->organizerMailbox = env('HR_CALENDAR_MAILBOX', env('MAIL_FROM_ADDRESS', ''));
    }

    // ── Get access token ──────────────────────────────────────────────────

    private function token(): string
    {
        $key = 'ms_graph_calendar_token_' . md5($this->clientId . $this->tenantId);
        return Cache::remember($key, 3500, function (): string {
            $res = Http::asForm()->timeout(10)->post(
                "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token",
                [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope'         => 'https://graph.microsoft.com/.default',
                ]
            );
            if ($res->failed()) {
                throw new \RuntimeException('Teams token request failed: ' . $res->body());
            }
            return $res->json('access_token');
        });
    }

    // ── Create or update a Teams calendar event ───────────────────────────

    public function createOrUpdateEvent(Interview $interview, array $attendeeEmails, string $subject, string $bodyHtml): array
    {
        $start    = $interview->scheduled_at;
        $end      = $start->copy()->addMinutes($interview->duration_minutes);
        $token    = $this->token();
        $mailbox  = $this->organizerMailbox;

        if (! $mailbox) {
            throw new \RuntimeException('HR_CALENDAR_MAILBOX is not configured. Cannot create Teams event.');
        }

        $payload = [
            'subject'        => $subject,
            'body'           => ['contentType' => 'HTML', 'content' => $bodyHtml],
            'start'          => ['dateTime' => $start->toIso8601String(), 'timeZone' => 'Africa/Nairobi'],
            'end'            => ['dateTime' => $end->toIso8601String(),   'timeZone' => 'Africa/Nairobi'],
            'isOnlineMeeting'         => true,
            'onlineMeetingProvider'   => 'teamsForBusiness',
            'allowNewTimeProposals'   => false,
            'attendees' => collect($attendeeEmails)->map(fn($email) => [
                'emailAddress' => ['address' => $email],
                'type'         => 'required',
            ])->values()->all(),
        ];

        if ($interview->location && ! str_contains(strtolower($interview->location ?? ''), 'teams')) {
            $payload['location'] = ['displayName' => $interview->location];
        }

        // Update existing event
        if ($interview->teams_event_id) {
            $res = Http::withToken($token)->timeout(20)->patch(
                "https://graph.microsoft.com/v1.0/users/{$mailbox}/events/{$interview->teams_event_id}",
                $payload
            );
            if ($res->failed()) {
                $graphMessage = $res->json('error.message') ?? $res->body();
                $graphCode    = $res->json('error.code') ?? '';
                Log::warning('Teams event update failed', ['status' => $res->status(), 'code' => $graphCode, 'body' => $res->body()]);
                if ($res->status() === 403 || str_contains(strtolower($graphMessage), 'access is denied') || str_contains(strtolower($graphCode), 'accessdenied')) {
                    throw new \RuntimeException(
                        "Access denied by Microsoft Graph API. " .
                        "Ensure the Azure AD app has 'Calendars.ReadWrite' application permission with admin consent."
                    );
                }
                throw new \RuntimeException($graphMessage);
            }
            return ['event_id' => $interview->teams_event_id, 'join_url' => $interview->teams_join_url];
        }

        // Create new event
        $res = Http::withToken($token)->timeout(20)->post(
            "https://graph.microsoft.com/v1.0/users/{$mailbox}/events",
            $payload
        );

        if ($res->failed()) {
            $graphMessage = $res->json('error.message') ?? $res->body();
            $graphCode    = $res->json('error.code') ?? '';
            Log::warning('Teams event creation failed', ['status' => $res->status(), 'code' => $graphCode, 'body' => $res->body()]);

            if ($res->status() === 403 || str_contains(strtolower($graphMessage), 'access is denied') || str_contains(strtolower($graphCode), 'accessdenied')) {
                throw new \RuntimeException(
                    "Access denied by Microsoft Graph API. " .
                    "Ensure the Azure AD app has the 'Calendars.ReadWrite' application permission (not delegated) " .
                    "with admin consent granted for the tenant, and that HR_CALENDAR_MAILBOX ({$mailbox}) is a valid mailbox in this tenant."
                );
            }

            throw new \RuntimeException($graphMessage);
        }

        $data    = $res->json();
        $joinUrl = $data['onlineMeeting']['joinUrl'] ?? $data['webLink'] ?? null;

        return [
            'event_id' => $data['id'],
            'join_url' => $joinUrl,
        ];
    }

    // ── Delete a Teams event ──────────────────────────────────────────────

    public function deleteEvent(string $eventId): void
    {
        try {
            $mailbox = $this->organizerMailbox;
            Http::withToken($this->token())->timeout(10)->delete(
                "https://graph.microsoft.com/v1.0/users/{$mailbox}/events/{$eventId}"
            );
        } catch (\Throwable $e) {
            Log::warning('Teams event delete failed', ['error' => $e->getMessage()]);
        }
    }

    // ── Issue per-panelist scoring token ──────────────────────────────────

    public static function issueScoringToken(InterviewPanelist $panelist, int $daysValid = 30): string
    {
        $token = bin2hex(random_bytes(32));
        $panelist->update([
            'scoring_token'            => $token,
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
