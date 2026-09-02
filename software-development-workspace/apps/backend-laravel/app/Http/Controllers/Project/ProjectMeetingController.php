<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectMeeting;
use App\Services\Project\ProjectMeetingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Project correspondence — Teams meetings scheduled against a project, and the
 * people worth inviting (the project's own team and its client's contacts).
 */
class ProjectMeetingController extends Controller
{
    public function __construct(private readonly ProjectMeetingService $service)
    {
    }

    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'data'        => $project->meetings()->with('creator:id,name')->get()->map(fn ($m) => $this->payload($m)),
            'suggestions' => $this->suggestedAttendees($project),
            'project_email' => $project->project_email,
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'subject'             => ['required', 'string', 'max:300'],
            'scheduled_at'        => ['required', 'date'],
            'duration_minutes'    => ['required', 'integer', 'min:5', 'max:480'],
            'agenda'              => ['nullable', 'string', 'max:2000'],
            'attendees'           => ['required', 'array', 'min:1'],
            'attendees.*.address' => ['required', 'email'],
            'attendees.*.name'    => ['nullable', 'string', 'max:160'],
        ]);

        try {
            $meeting = $this->service->schedule($project, $data, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->payload($meeting->load('creator:id,name'))], 201);
    }

    /** Pull the Teams recording link and transcript for a meeting that ran. */
    public function sync(Project $project, ProjectMeeting $meeting): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($meeting->project_id === $project->id, 404);

        try {
            $result = $this->service->syncArtifacts($meeting);
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

    public function destroy(Project $project, ProjectMeeting $meeting): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($meeting->project_id === $project->id, 404);

        try {
            $this->service->cancel($meeting);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->payload($meeting->fresh('creator:id,name'))]);
    }

    /**
     * Who to offer as invitees: the internal team (lead, managers, leads,
     * governance and role members) by work email, and the client's own
     * contacts by their email. De-duplicated on address; only those with an
     * address are useful to a calendar.
     */
    private function suggestedAttendees(Project $project): array
    {
        $out = [];
        $add = function (?string $name, ?string $address, string $group) use (&$out) {
            $address = $address ? strtolower(trim($address)) : null;
            if (! $address || isset($out[$address])) {
                return;
            }
            $out[$address] = ['name' => $name ?: $address, 'address' => $address, 'group' => $group];
        };

        $lead = $project->projectLead;
        if ($lead) {
            $add(trim($lead->first_name . ' ' . $lead->last_name), $lead->work_email, 'Internal');
        }

        $project->loadMissing('people.employee:id,first_name,last_name,work_email');
        foreach ($project->people as $p) {
            $e = $p->employee;
            if ($e) {
                $add(trim($e->first_name . ' ' . $e->last_name), $e->work_email, 'Internal');
            }
        }

        $project->loadMissing('account.contacts');
        if ($project->account) {
            foreach ($project->account->contacts as $c) {
                $add(trim($c->first_name . ' ' . $c->last_name), $c->email, 'Client');
            }
        }

        return array_values($out);
    }

    private function payload(ProjectMeeting $m): array
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
