<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Support\Ticket;
use App\Models\Support\TicketMeeting;
use App\Services\Support\TicketMeetingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Teams calls scheduled against a ticket.
 */
class TicketMeetingController extends Controller
{
    public function __construct(private readonly TicketMeetingService $service) {}

    public function store(Request $request, int $ticket): JsonResponse
    {
        $record = Ticket::findOrFail($ticket);
        $this->authorize('update', Ticket::class);

        $data = $request->validate([
            'subject'                  => ['required', 'string', 'max:300'],
            'scheduled_at'             => ['required', 'date'],
            'duration_minutes'         => ['required', 'integer', 'min:5', 'max:480'],
            'agenda'                   => ['nullable', 'string', 'max:2000'],
            'attendees'                => ['required', 'array', 'min:1'],
            'attendees.*.address'      => ['required', 'email'],
            'attendees.*.name'         => ['nullable', 'string', 'max:160'],
        ]);

        try {
            $meeting = $this->service->schedule($record, $data, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $meeting], 201);
    }

    public function destroy(int $ticket, TicketMeeting $meeting): JsonResponse
    {
        $record = Ticket::findOrFail($ticket);
        $this->authorize('update', Ticket::class);
        abort_unless($meeting->support_ticket_id === $record->id, 404);

        try {
            $this->service->cancel($meeting);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $meeting->fresh()]);
    }
}
