<?php

namespace App\Services\Task;

use App\Models\Task\Task;
use App\Models\Task\TaskMeeting;
use App\Models\User;
use App\Services\Integration\CalendarConnectionResolver;
use App\Services\Integration\TeamsMeetingClient;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Scheduling a Teams meeting against a task that has no project or ticket home.
 *
 * The same calendar connection and Graph client as project and ticket calls —
 * one place knows how to make a Teams meeting. Also pulls the recording link
 * and transcript once the meeting has run, exactly like the project side.
 */
class TaskMeetingService
{
    public function schedule(Task $task, array $data, ?User $actor = null): TaskMeeting
    {
        $connection = CalendarConnectionResolver::resolve($task->organization_id);
        $mailbox    = $this->organizerMailbox();

        $start = Carbon::parse($data['scheduled_at']);
        $end   = $start->copy()->addMinutes((int) $data['duration_minutes']);

        $bodyHtml = "<p>Meeting for task <strong>{$task->reference}</strong> — {$task->title}</p>"
            . (! empty($data['agenda']) ? '<p>' . nl2br(e($data['agenda'])) . '</p>' : '');

        $result = (new TeamsMeetingClient($connection))->createEvent($mailbox, [
            'subject'   => $data['subject'],
            'body_html' => $bodyHtml,
            'start'     => $start,
            'end'       => $end,
            'attendees' => $data['attendees'],
        ]);

        return TaskMeeting::create([
            'organization_id'  => $task->organization_id,
            'task_id'          => $task->id,
            'subject'          => $data['subject'],
            'scheduled_at'     => $start,
            'duration_minutes' => $data['duration_minutes'],
            'attendees'        => $data['attendees'],
            'graph_event_id'   => $result['event_id'],
            'join_url'         => $result['join_url'],
            'status'           => TaskMeeting::STATUS_SCHEDULED,
            'created_by'       => $actor?->id,
        ]);
    }

    /**
     * Pull the recording link and transcript for a task meeting that has run.
     *
     * @return array{status: string, meeting: TaskMeeting}
     */
    public function syncArtifacts(TaskMeeting $meeting): array
    {
        if (! $meeting->join_url) {
            return ['status' => 'not_ready', 'meeting' => $meeting];
        }

        $connection = CalendarConnectionResolver::resolve($meeting->organization_id);
        $client     = new TeamsMeetingClient($connection);

        // The online-meetings API needs the organiser's object id, not the UPN.
        // Teams embeds it in the join URL's `context` (Oid), so we read it there
        // and avoid a directory lookup.
        $organizerId = $this->organizerIdFromJoinUrl($meeting->join_url)
            ?? $client->resolveUserId($this->organizerMailbox());

        $meetingId = $client->onlineMeetingId($organizerId, $meeting->join_url);
        if (! $meetingId) {
            return ['status' => 'not_ready', 'meeting' => $meeting];
        }

        $update = [];

        // Latest recording — the link to where it plays only, never the media.
        $recordings = $client->recordings($organizerId, $meetingId);
        if ($recordings) {
            $latest     = collect($recordings)->sortByDesc('createdDateTime')->first();
            $streamLink = $latest['recordingContentUrl'] ?? $latest['contentUrl'] ?? $latest['webUrl'] ?? null;
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

    public function cancel(TaskMeeting $meeting): TaskMeeting
    {
        if ($meeting->status === TaskMeeting::STATUS_CANCELLED) {
            return $meeting;
        }

        if ($meeting->graph_event_id) {
            $connection = CalendarConnectionResolver::resolve($meeting->organization_id);
            (new TeamsMeetingClient($connection))->cancelEvent($this->organizerMailbox(), $meeting->graph_event_id);
        }

        $meeting->update(['status' => TaskMeeting::STATUS_CANCELLED, 'cancelled_at' => now()]);

        return $meeting;
    }

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

    private function organizerMailbox(): string
    {
        $mailbox = env('HR_CALENDAR_MAILBOX', env('MAIL_FROM_ADDRESS', ''));
        if (! $mailbox) {
            throw new RuntimeException('HR_CALENDAR_MAILBOX is not configured. Cannot create Teams meetings.');
        }
        return $mailbox;
    }
}
