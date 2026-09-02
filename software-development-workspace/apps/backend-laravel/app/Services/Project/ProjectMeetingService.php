<?php

namespace App\Services\Project;

use App\Models\Project\Project;
use App\Models\Project\ProjectMeeting;
use App\Models\User;
use App\Services\Integration\CalendarConnectionResolver;
use App\Services\Integration\TeamsMeetingClient;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Scheduling a Teams meeting against a project.
 *
 * The same calendar connection and Graph client as ticket calls and interview
 * scheduling — one place knows how to make a Teams meeting, not one per module.
 */
class ProjectMeetingService
{
    public function schedule(Project $project, array $data, ?User $actor = null): ProjectMeeting
    {
        $connection = CalendarConnectionResolver::resolve($project->organization_id);
        $mailbox    = $this->organizerMailbox($project);

        $start = Carbon::parse($data['scheduled_at']);
        $end   = $start->copy()->addMinutes((int) $data['duration_minutes']);

        $bodyHtml = "<p>Project meeting — <strong>{$project->name}</strong>"
            . ($project->reference ? " ({$project->reference})" : '') . '</p>'
            . (! empty($data['agenda']) ? '<p>' . nl2br(e($data['agenda'])) . '</p>' : '');

        $result = (new TeamsMeetingClient($connection))->createEvent($mailbox, [
            'subject'   => $data['subject'],
            'body_html' => $bodyHtml,
            'start'     => $start,
            'end'       => $end,
            'attendees' => $data['attendees'],
        ]);

        return ProjectMeeting::create([
            'organization_id'  => $project->organization_id,
            'task_id'          => $data['task_id'] ?? null,
            'project_id'       => $project->id,
            'subject'          => $data['subject'],
            'scheduled_at'     => $start,
            'duration_minutes' => $data['duration_minutes'],
            'attendees'        => $data['attendees'],
            'graph_event_id'   => $result['event_id'],
            'join_url'         => $result['join_url'],
            'status'           => ProjectMeeting::STATUS_SCHEDULED,
            'created_by'       => $actor?->id,
        ]);
    }

    /**
     * Pull the recording link and transcript for a meeting that has happened.
     *
     * Returns the meeting and a status the UI can explain: 'updated' when a
     * recording or transcript landed, 'not_ready' when the online meeting isn't
     * resolvable yet (not started, or Graph hasn't caught up), 'nothing_yet'
     * when it resolved but neither artifact exists. Permission problems surface
     * as a RuntimeException with a clear message.
     *
     * @return array{status: string, meeting: ProjectMeeting}
     */
    public function syncArtifacts(ProjectMeeting $meeting): array
    {
        if (! $meeting->join_url) {
            return ['status' => 'not_ready', 'meeting' => $meeting];
        }

        $connection = CalendarConnectionResolver::resolve($meeting->organization_id);
        $client     = new TeamsMeetingClient($connection);

        // The online-meetings API needs the organiser's object id, not the UPN.
        // Teams embeds it in the join URL's `context` (Oid), so we read it from
        // there and avoid a directory lookup — which keeps this working with the
        // meeting-only Graph permissions the Support connection already holds.
        $organizerId = $this->organizerIdFromJoinUrl($meeting->join_url)
            ?? $client->resolveUserId($this->organizerMailbox($meeting->project));

        $meetingId = $client->onlineMeetingId($organizerId, $meeting->join_url);
        if (! $meetingId) {
            return ['status' => 'not_ready', 'meeting' => $meeting];
        }

        $update = [];

        // Latest recording — we keep ONLY the link to where it plays (Stream on
        // SharePoint / OneDrive), never the media. The heavy file stays in
        // Microsoft's storage; we never call the recording's /content endpoint.
        $recordings = $client->recordings($organizerId, $meetingId);
        if ($recordings) {
            $latest = collect($recordings)->sortByDesc('createdDateTime')->first();
            $streamLink = $this->recordingStreamLink($latest);
            if ($streamLink) {
                $update['recording_url'] = $streamLink;
            }
        }

        // Latest transcript — store the WebVTT text itself.
        $transcripts = $client->transcripts($organizerId, $meetingId);
        if ($transcripts) {
            $latest = collect($transcripts)->sortByDesc('createdDateTime')->first();
            $text   = $client->transcriptContent($organizerId, $meetingId, $latest['id']);
            if ($text) {
                $update['transcript'] = $text;
            }
        }

        if ($update) {
            $meeting->update($update);
            return ['status' => 'updated', 'meeting' => $meeting->fresh()];
        }

        return ['status' => 'nothing_yet', 'meeting' => $meeting];
    }

    public function cancel(ProjectMeeting $meeting): ProjectMeeting
    {
        if ($meeting->status === ProjectMeeting::STATUS_CANCELLED) {
            return $meeting;
        }

        if ($meeting->graph_event_id) {
            $connection = CalendarConnectionResolver::resolve($meeting->organization_id);
            (new TeamsMeetingClient($connection))->cancelEvent($this->organizerMailbox($meeting->project), $meeting->graph_event_id);
        }

        $meeting->update(['status' => ProjectMeeting::STATUS_CANCELLED, 'cancelled_at' => now()]);

        return $meeting;
    }

    /**
     * The link to where a recording plays — Stream on SharePoint / OneDrive —
     * taken from whichever URL field Graph populates on the callRecording. Never
     * the /content media endpoint: we store a link, not the video.
     */
    private function recordingStreamLink(array $recording): ?string
    {
        return $recording['recordingContentUrl']
            ?? $recording['contentUrl']
            ?? $recording['webUrl']
            ?? null;
    }

    /**
     * The organiser's object id, read from the Teams join URL's `context`
     * (which carries `{"Tid":..., "Oid":...}`). Null if the URL has no such
     * context — an externally-pasted link, say — so the caller can fall back.
     */
    private function organizerIdFromJoinUrl(?string $joinUrl): ?string
    {
        if (! $joinUrl) {
            return null;
        }

        $query = parse_url($joinUrl, PHP_URL_QUERY);
        if (! $query) {
            return null;
        }

        parse_str($query, $params);
        $context = $params['context'] ?? null;
        if (! $context) {
            return null;
        }

        $decoded = json_decode($context, true);

        return is_array($decoded) ? ($decoded['Oid'] ?? null) : null;
    }

    /**
     * The project's own mailbox organises the meeting when one is set;
     * otherwise the shared calendar mailbox, like every other module.
     */
    private function organizerMailbox(Project $project): string
    {
        $mailbox = $project->project_email
            ?: env('HR_CALENDAR_MAILBOX', env('MAIL_FROM_ADDRESS', ''));

        if (! $mailbox) {
            throw new RuntimeException('No project mailbox and HR_CALENDAR_MAILBOX is not configured. Cannot create Teams meetings.');
        }

        return $mailbox;
    }
}
