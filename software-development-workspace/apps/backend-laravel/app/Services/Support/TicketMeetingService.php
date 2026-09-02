<?php

namespace App\Services\Support;

use App\Models\Support\Ticket;
use App\Models\Support\TicketMeeting;
use App\Models\User;
use App\Services\Integration\CalendarConnectionResolver;
use App\Services\Integration\TeamsMeetingClient;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Scheduling a Teams call against a ticket.
 *
 * Same calendar connection and the same Graph client as interview scheduling
 * — see CalendarConnectionResolver and TeamsMeetingClient — so there is one
 * place that knows how to make a Teams meeting, not one per module.
 */
class TicketMeetingService
{
    public function schedule(Ticket $ticket, array $data, ?User $actor = null): TicketMeeting
    {
        $connection = CalendarConnectionResolver::resolve($ticket->organization_id);
        $mailbox    = $this->organizerMailbox();

        $start = Carbon::parse($data['scheduled_at']);
        $end   = $start->copy()->addMinutes((int) $data['duration_minutes']);

        $bodyHtml = "<p>Call regarding ticket <strong>{$ticket->reference}</strong> — {$ticket->subject}</p>"
            . ($data['agenda'] ?? null ? '<p>' . nl2br(e($data['agenda'])) . '</p>' : '');

        $result = (new TeamsMeetingClient($connection))->createEvent($mailbox, [
            'subject'   => $data['subject'],
            'body_html' => $bodyHtml,
            'start'     => $start,
            'end'       => $end,
            'attendees' => $data['attendees'],
        ]);

        return TicketMeeting::create([
            'organization_id'   => $ticket->organization_id,
            'task_id'           => $data['task_id'] ?? null,
            'support_ticket_id' => $ticket->id,
            'subject'           => $data['subject'],
            'scheduled_at'      => $start,
            'duration_minutes'  => $data['duration_minutes'],
            'attendees'         => $data['attendees'],
            'graph_event_id'    => $result['event_id'],
            'join_url'          => $result['join_url'],
            'status'            => TicketMeeting::STATUS_SCHEDULED,
            'created_by'        => $actor?->id,
        ]);
    }

    public function cancel(TicketMeeting $meeting): TicketMeeting
    {
        if ($meeting->status === TicketMeeting::STATUS_CANCELLED) {
            return $meeting;
        }

        if ($meeting->graph_event_id) {
            $connection = CalendarConnectionResolver::resolve($meeting->organization_id);
            (new TeamsMeetingClient($connection))->cancelEvent($this->organizerMailbox(), $meeting->graph_event_id);
        }

        $meeting->update(['status' => TicketMeeting::STATUS_CANCELLED, 'cancelled_at' => now()]);

        return $meeting;
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
