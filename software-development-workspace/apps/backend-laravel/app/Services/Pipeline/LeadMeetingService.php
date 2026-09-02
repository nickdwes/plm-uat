<?php

namespace App\Services\Pipeline;

use App\Models\Pipeline\Lead;
use App\Models\Pipeline\LeadMeeting;
use App\Models\User;
use App\Services\Integration\CalendarConnectionResolver;
use App\Services\Integration\TeamsMeetingClient;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Booking a Teams meeting against a lead.
 *
 * The same flow the project already uses, with one difference that matters: a
 * project organises from its own mailbox and a lead has none, so the invitation
 * goes out from the organisation's calendar address. That is the honest
 * arrangement anyway — at this stage the customer is meeting the company, not
 * a project that does not exist yet.
 */
class LeadMeetingService
{
    public function schedule(Lead $lead, array $data, ?User $actor = null): LeadMeeting
    {
        $connection = CalendarConnectionResolver::resolve($lead->organization_id);
        $mailbox    = $this->organizerMailbox();

        $start = Carbon::parse($data['scheduled_at']);
        $end   = $start->copy()->addMinutes((int) $data['duration_minutes']);

        $bodyHtml = '<p>Regarding <strong>' . e($lead->name) . '</strong>'
            . ($lead->reference ? ' (' . e($lead->reference) . ')' : '') . '</p>'
            . (! empty($data['agenda']) ? '<p>' . nl2br(e($data['agenda'])) . '</p>' : '');

        $result = (new TeamsMeetingClient($connection))->createEvent($mailbox, [
            'subject'   => $data['subject'],
            'body_html' => $bodyHtml,
            'start'     => $start,
            'end'       => $end,
            'attendees' => $data['attendees'],
        ]);

        return LeadMeeting::create([
            'organization_id'  => $lead->organization_id,
            'lead_id'          => $lead->id,
            'subject'          => $data['subject'],
            'scheduled_at'     => $start,
            'duration_minutes' => $data['duration_minutes'],
            'agenda'           => $data['agenda'] ?? null,
            'attendees'        => $data['attendees'],
            'graph_event_id'   => $result['event_id'],
            'join_url'         => $result['join_url'],
            'status'           => LeadMeeting::STATUS_SCHEDULED,
            'created_by'       => $actor?->id,
        ]);
    }

    /**
     * Call it off.
     *
     * Cancelled rather than deleted: the attendees were invited, and a meeting
     * that was called off is a fact about the pursuit. Graph is told first —
     * if the calendar cancellation fails, the row must not say cancelled while
     * everyone's diary still says it is on.
     */
    public function cancel(LeadMeeting $meeting): LeadMeeting
    {
        if ($meeting->status === LeadMeeting::STATUS_CANCELLED) {
            return $meeting;
        }

        if ($meeting->graph_event_id) {
            $connection = CalendarConnectionResolver::resolve($meeting->organization_id);
            (new TeamsMeetingClient($connection))->cancelEvent($this->organizerMailbox(), $meeting->graph_event_id);
        }

        $meeting->update([
            'status'       => LeadMeeting::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        return $meeting->fresh();
    }

    private function organizerMailbox(): string
    {
        $mailbox = env('HR_CALENDAR_MAILBOX', env('MAIL_FROM_ADDRESS', ''));

        if (! $mailbox) {
            throw new RuntimeException(
                'HR_CALENDAR_MAILBOX is not configured, so Teams meetings cannot be created.',
            );
        }

        return $mailbox;
    }
}
