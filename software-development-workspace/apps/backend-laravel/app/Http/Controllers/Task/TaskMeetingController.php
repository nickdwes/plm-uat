<?php

namespace App\Http\Controllers\Task;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectMeeting;
use App\Models\Support\Ticket;
use App\Models\Support\TicketMeeting;
use App\Models\Task\Task;
use App\Models\Task\TaskMeeting;
use App\Services\Project\ProjectMeetingService;
use App\Services\Support\TicketMeetingService;
use App\Services\Task\TaskMeetingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Meetings scheduled from a task.
 *
 * A task is only the surface. Where a meeting is tracked follows what the task
 * is: a project task's call is logged on the project (its Correspondence tab),
 * a support task's call on the ticket (its Meetings tab), and a task tied to
 * neither keeps its own. Each row carries the task_id, so this tab lists the
 * calls raised here wherever they actually live — one record, seen from both
 * sides.
 */
class TaskMeetingController extends Controller
{
    public function __construct(
        private readonly ProjectMeetingService $projectMeetings,
        private readonly TicketMeetingService $ticketMeetings,
        private readonly TaskMeetingService $taskMeetings,
    ) {
    }

    public function index(Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $dest = $this->destination($task);

        $meetings = $this->meetingsFor($task, $dest)
            ->map(fn (Model $m) => $this->payload($m))
            ->values();

        return response()->json([
            'data'        => $meetings,
            'destination' => $this->destinationPayload($dest),
            'suggestions' => $this->suggestedAttendees($task, $dest),
        ]);
    }

    public function store(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $data = $request->validate([
            'subject'             => ['required', 'string', 'max:300'],
            'scheduled_at'        => ['required', 'date'],
            'duration_minutes'    => ['required', 'integer', 'min:5', 'max:480'],
            'agenda'              => ['nullable', 'string', 'max:2000'],
            'attendees'           => ['required', 'array', 'min:1'],
            'attendees.*.address' => ['required', 'email'],
            'attendees.*.name'    => ['nullable', 'string', 'max:160'],
        ]);

        $dest         = $this->destination($task);
        $data['task_id'] = $task->id;

        try {
            $meeting = match ($dest['type']) {
                'project' => $this->projectMeetings->schedule($dest['model'], $data, $request->user()),
                'ticket'  => $this->ticketMeetings->schedule($dest['model'], $data, $request->user()),
                default   => $this->taskMeetings->schedule($task, $data, $request->user()),
            };
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->payload($meeting->load('creator:id,name'))], 201);
    }

    /** Pull the Teams recording link and transcript for a meeting that ran. */
    public function sync(Task $task, int $meeting): JsonResponse
    {
        $this->authorize('update', $task);

        $dest = $this->destination($task);
        $row  = $this->findMeeting($task, $dest, $meeting);

        try {
            $result = match ($dest['type']) {
                'project' => $this->projectMeetings->syncArtifacts($row),
                'task', 'none' => $this->taskMeetings->syncArtifacts($row),
                // A ticket call has no artifact pull of its own yet.
                default   => ['status' => 'not_ready', 'meeting' => $row],
            };
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $messages = [
            'updated'     => 'Recording and transcript updated.',
            'nothing_yet' => 'No recording or transcript is available for this meeting yet.',
            'not_ready'   => 'This meeting has no recording yet — it may not have taken place, or Teams has not finished processing it.',
        ];

        return response()->json([
            'data'    => $this->payload($result['meeting']->load('creator:id,name')),
            'status'  => $result['status'],
            'message' => $messages[$result['status']] ?? null,
        ]);
    }

    public function destroy(Task $task, int $meeting): JsonResponse
    {
        $this->authorize('update', $task);

        $dest = $this->destination($task);
        $row  = $this->findMeeting($task, $dest, $meeting);

        try {
            match ($dest['type']) {
                'project' => $this->projectMeetings->cancel($row),
                'ticket'  => $this->ticketMeetings->cancel($row),
                default   => $this->taskMeetings->cancel($row),
            };
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->payload($row->fresh('creator:id,name'))]);
    }

    // ── Where a task's meetings live ─────────────────────────────────────────

    /**
     * What carries this task's meetings. A support task (raised from a ticket)
     * takes precedence over a project link; a task with neither keeps its own.
     *
     * @return array{type: string, model: Model|null}
     */
    private function destination(Task $task): array
    {
        if ($task->generated_from_type === Ticket::class && $task->generated_from_id) {
            $ticket = Ticket::find($task->generated_from_id);
            if ($ticket) {
                return ['type' => 'ticket', 'model' => $ticket];
            }
        }

        if ($task->project_id && ($project = Project::find($task->project_id))) {
            return ['type' => 'project', 'model' => $project];
        }

        return ['type' => 'none', 'model' => null];
    }

    private function meetingsFor(Task $task, array $dest)
    {
        return match ($dest['type']) {
            'project' => ProjectMeeting::where('task_id', $task->id)->with('creator:id,name')->orderByDesc('scheduled_at')->get(),
            'ticket'  => TicketMeeting::where('task_id', $task->id)->with('creator:id,name')->orderByDesc('scheduled_at')->get(),
            default   => TaskMeeting::where('task_id', $task->id)->with('creator:id,name')->orderByDesc('scheduled_at')->get(),
        };
    }

    private function findMeeting(Task $task, array $dest, int $meetingId): Model
    {
        $row = match ($dest['type']) {
            'project' => ProjectMeeting::where('task_id', $task->id)->find($meetingId),
            'ticket'  => TicketMeeting::where('task_id', $task->id)->find($meetingId),
            default   => TaskMeeting::where('task_id', $task->id)->find($meetingId),
        };

        abort_unless($row, 404);

        return $row;
    }

    /**
     * A short note the tab shows so it is plain where the call is tracked, with
     * a link to that project or ticket.
     */
    private function destinationPayload(array $dest): array
    {
        return match ($dest['type']) {
            'project' => [
                'type'  => 'project',
                'id'    => $dest['model']->id,
                'label' => $dest['model']->name,
                'note'  => 'Logged on this project’s Correspondence.',
            ],
            'ticket' => [
                'type'  => 'ticket',
                'id'    => $dest['model']->id,
                'label' => $dest['model']->reference,
                'note'  => 'Shown on this ticket’s Meetings.',
            ],
            default => [
                'type'  => 'none',
                'id'    => null,
                'label' => null,
                'note'  => 'Tracked here on the task.',
            ],
        };
    }

    /**
     * Who to offer as invitees: the people on the task, plus the destination's
     * own natural contact — the project lead or the ticket's requester.
     * De-duplicated on address; only those with an email are useful.
     */
    private function suggestedAttendees(Task $task, array $dest): array
    {
        $out = [];
        $add = function (?string $name, ?string $address, string $group) use (&$out) {
            $address = $address ? strtolower(trim($address)) : null;
            if (! $address || isset($out[$address])) {
                return;
            }
            $out[$address] = ['name' => $name ?: $address, 'address' => $address, 'group' => $group];
        };

        $task->loadMissing(['assignee:id,first_name,last_name,work_email', 'members.employee:id,first_name,last_name,work_email']);

        if ($task->assignee) {
            $add(trim($task->assignee->first_name . ' ' . $task->assignee->last_name), $task->assignee->work_email, 'Task');
        }
        foreach ($task->members as $m) {
            if ($m->employee) {
                $add(trim($m->employee->first_name . ' ' . $m->employee->last_name), $m->employee->work_email, 'Task');
            }
        }

        if ($dest['type'] === 'project') {
            $lead = $dest['model']->projectLead;
            if ($lead) {
                $add(trim($lead->first_name . ' ' . $lead->last_name), $lead->work_email, 'Project');
            }
        } elseif ($dest['type'] === 'ticket') {
            $ticket = $dest['model'];
            $ticket->loadMissing(['contact', 'emails.recipients']);

            if ($ticket->contact) {
                $c = $ticket->contact;
                $add(trim($c->first_name . ' ' . $c->last_name), $c->email, 'Requester');
            } elseif ($ticket->requester_email) {
                $add($ticket->requester_name, $ticket->requester_email, 'Requester');
            }

            // Everyone already on the ticket's email thread — the to/cc lines of
            // the mail that raised it — so the same people copied on the issue
            // are offered for the call, not retyped by hand.
            foreach ($ticket->emails as $email) {
                foreach ($email->recipients as $r) {
                    if ($r->address && in_array(strtolower((string) $r->kind), ['to', 'cc'], true)) {
                        $add($r->name, $r->address, 'Copied');
                    }
                }
            }
        }

        return array_values($out);
    }

    private function payload(Model $m): array
    {
        return [
            'id'               => $m->id,
            'subject'          => $m->subject,
            'scheduled_at'     => $m->scheduled_at?->toIso8601String(),
            'duration_minutes' => $m->duration_minutes,
            'attendees'        => $m->attendees ?? [],
            'join_url'         => $m->join_url,
            'recording_url'    => $m->recording_url,
            'transcript'       => $m->transcript,
            'has_transcript'   => filled($m->transcript),
            'status'           => $m->status,
            'created_by'       => $m->relationLoaded('creator') ? $m->creator?->name : null,
            'created_at'       => $m->created_at?->toIso8601String(),
        ];
    }
}
