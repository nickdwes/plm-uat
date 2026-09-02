<?php

namespace App\Http\Controllers\Pipeline;

use App\Http\Controllers\Controller;
use App\Models\Pipeline\Lead;
use App\Models\Pipeline\LeadEmail;
use App\Models\Pipeline\LeadMeeting;
use App\Models\Pipeline\LeadRequest;
use App\Services\Pipeline\LeadMailService;
use App\Services\Pipeline\LeadMeetingService;
use App\Support\Account\ReferenceSequence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * A lead's correspondence: its meetings, its mail, and what pursuing it costs.
 *
 * One controller for three tabs because they are one screen and one permission
 * question — whoever may work a lead may arrange a call about it, read the mail
 * on it, and ask for a car to get to it. Approving the spend is the exception,
 * and it is the only thing here gated differently.
 */
class LeadCorrespondenceController extends Controller
{
    public function __construct(
        private readonly LeadMeetingService $meetings,
        private readonly LeadMailService $mail,
    ) {
    }

    // ── Meetings ──────────────────────────────────────────────────────────

    public function meetings(Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        return response()->json([
            'data' => $lead->meetings()->with('creator:id,name')->get()
                ->map(fn (LeadMeeting $m) => $this->meetingPayload($m)),

            // The people worth inviting, so the form is a choice rather than
            // an empty box somebody has to go and look addresses up for.
            'suggested_attendees' => $this->suggestedAttendees($lead),
        ]);
    }

    public function scheduleMeeting(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('update', $lead);

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
            $meeting = $this->meetings->schedule($lead, $data, $request->user());
        } catch (RuntimeException $e) {
            // Configuration and Graph problems are the caller's to read, not a
            // 500 with a stack trace behind it.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(
            ['data' => $this->meetingPayload($meeting->load('creator:id,name'))],
            201,
        );
    }

    public function cancelMeeting(Lead $lead, LeadMeeting $meeting): JsonResponse
    {
        $this->authorize('update', $lead);
        abort_unless($meeting->lead_id === $lead->id, 404);

        try {
            $meeting = $this->meetings->cancel($meeting);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->meetingPayload($meeting->load('creator:id,name'))]);
    }

    // ── Emails ────────────────────────────────────────────────────────────

    public function emails(Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        return response()->json([
            'data' => $lead->emails()->get()->map(fn (LeadEmail $e) => $this->emailPayload($e)),

            // Shown alongside, because an empty list means one of two very
            // different things: nobody has written, or nobody has been named.
            'correspondents' => $this->mail->correspondents($lead),
        ]);
    }

    /** Pull the mailbox for anything new involving this lead's people. */
    public function syncEmails(Lead $lead): JsonResponse
    {
        $this->authorize('update', $lead);

        try {
            $result = $this->mail->sync($lead);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $messages = [
            'ok'                => "Checked {$result['addresses']} address(es) — {$result['new']} new message(s).",
            'no_correspondents' => 'Nobody is named on this lead yet, so there are no addresses to search for. '
                . 'Add a decision maker under Qualifications, or contacts on the customer.',
        ];

        return response()->json([
            'message' => $messages[$result['status']] ?? 'Nothing to do.',
            'result'  => $result,
            'data'    => $lead->emails()->get()->map(fn (LeadEmail $e) => $this->emailPayload($e)),
        ]);
    }

    // ── Requests ──────────────────────────────────────────────────────────

    public function requests(Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        return response()->json([
            'data'  => $lead->requests()->with(['requester:id,name', 'decider:id,name'])->get()
                ->map(fn (LeadRequest $r) => $this->requestPayload($r)),
            'kinds' => collect(LeadRequest::KINDS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
        ]);
    }

    public function storeRequest(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('update', $lead);

        $data = $request->validate([
            'kind'           => ['required', Rule::in(array_keys(LeadRequest::KINDS))],
            'purpose'        => ['required', 'string', 'max:255'],
            'location'       => ['nullable', 'string', 'max:255'],
            'needed_on'      => ['nullable', 'date'],
            'people'         => ['nullable', 'integer', 'min:1', 'max:500'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'currency'       => ['nullable', 'string', 'size:3'],
            'notes'          => ['nullable', 'string', 'max:5000'],
        ]);

        $leadRequest = LeadRequest::create($data + [
            'organization_id' => $lead->organization_id,
            'lead_id'         => $lead->id,
            'reference'       => ReferenceSequence::next('LR', 'lead_requests', $lead->organization_id),
            'currency'        => $data['currency'] ?? 'KES',
            'status'          => LeadRequest::STATUS_PENDING,
            'requested_by'    => $request->user()?->id,
        ]);

        return response()->json(
            ['data' => $this->requestPayload($leadRequest->load(['requester:id,name', 'decider:id,name']))],
            201,
        );
    }

    /**
     * Approve or decline.
     *
     * Gated on `close` rather than `update`: saying yes to spending is not the
     * same authority as working the lead, and the person who asked should not
     * be the person who grants it. Declining says why — a refusal with no
     * reason gets asked again next week.
     */
    public function decideRequest(Request $request, Lead $lead, LeadRequest $leadRequest): JsonResponse
    {
        $this->authorize('close', $lead);
        abort_unless($leadRequest->lead_id === $lead->id, 404);

        $data = $request->validate([
            'status'           => ['required', Rule::in([LeadRequest::STATUS_APPROVED, LeadRequest::STATUS_DECLINED])],
            'decision_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        abort_if(
            $leadRequest->isDecided(),
            422,
            'This request has already been answered.',
        );

        if ($data['status'] === LeadRequest::STATUS_DECLINED && empty($data['decision_comment'])) {
            return response()->json([
                'message' => 'Say why it is declined.',
                'errors'  => ['decision_comment' => ['Say why it is declined — a refusal with no reason gets asked again next week.']],
            ], 422);
        }

        $leadRequest->update($data + [
            'decided_at' => now(),
            'decided_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->requestPayload($leadRequest->fresh(['requester:id,name', 'decider:id,name']))]);
    }

    /** It happened. Only an approved request can be, which is the point of it. */
    public function fulfilRequest(Request $request, Lead $lead, LeadRequest $leadRequest): JsonResponse
    {
        $this->authorize('update', $lead);
        abort_unless($leadRequest->lead_id === $lead->id, 404);

        abort_unless(
            $leadRequest->status === LeadRequest::STATUS_APPROVED,
            422,
            'Only an approved request can be marked as provided.',
        );

        $leadRequest->update(['status' => LeadRequest::STATUS_FULFILLED]);

        return response()->json(['data' => $this->requestPayload($leadRequest->fresh(['requester:id,name', 'decider:id,name']))]);
    }

    public function destroyRequest(Lead $lead, LeadRequest $leadRequest): JsonResponse
    {
        $this->authorize('update', $lead);
        abort_unless($leadRequest->lead_id === $lead->id, 404);

        // Withdrawing your own ask is fine; unpicking somebody's decision is
        // not — that would erase the record of what was refused and by whom.
        abort_if($leadRequest->isDecided(), 422, 'An answered request stays on the record.');

        $leadRequest->delete();

        return response()->json(['message' => 'Request withdrawn.']);
    }

    // ── Payloads ──────────────────────────────────────────────────────────

    private function meetingPayload(LeadMeeting $m): array
    {
        return [
            'id'               => $m->id,
            'subject'          => $m->subject,
            'scheduled_at'     => $m->scheduled_at?->toIso8601String(),
            'duration_minutes' => $m->duration_minutes,
            'agenda'           => $m->agenda,
            'attendees'        => $m->attendees ?? [],
            'join_url'         => $m->join_url,
            'recording_url'    => $m->recording_url,
            'has_transcript'   => filled($m->transcript),
            'status'           => $m->status,
            'cancelled_at'     => $m->cancelled_at?->toIso8601String(),
            'created_by'       => $m->creator?->name,
            'is_past'          => $m->scheduled_at?->isPast() ?? false,
        ];
    }

    private function emailPayload(LeadEmail $e): array
    {
        return [
            'id'              => $e->id,
            'subject'         => $e->subject,
            'from_name'       => $e->from_name,
            'from_email'      => $e->from_email,
            'body_preview'    => $e->body_preview,
            'body_html'       => $e->body_html,
            'to_recipients'   => $e->to_recipients ?? [],
            'cc_recipients'   => $e->cc_recipients ?? [],
            'has_attachments' => $e->has_attachments,
            'received_at'     => $e->received_at?->toIso8601String(),
            // Why this message is on this lead at all.
            'matched_address' => $e->matched_address,
        ];
    }

    private function requestPayload(LeadRequest $r): array
    {
        return [
            'id'               => $r->id,
            'reference'        => $r->reference,
            'kind'             => $r->kind,
            'kind_label'       => LeadRequest::KINDS[$r->kind] ?? $r->kind,
            'purpose'          => $r->purpose,
            'location'         => $r->location,
            'needed_on'        => $r->needed_on?->toDateString(),
            'people'           => $r->people,
            'estimated_cost'   => $r->estimated_cost,
            'currency'         => $r->currency,
            'notes'            => $r->notes,
            'status'           => $r->status,
            'decision_comment' => $r->decision_comment,
            'decided_at'       => $r->decided_at?->toIso8601String(),
            'decided_by'       => $r->decider?->name,
            'requested_by'     => $r->requester?->name,
            'created_at'       => $r->created_at?->toIso8601String(),
        ];
    }

    /**
     * Who to invite.
     *
     * Two sides, marked as such: theirs — the lead's named stakeholders and the
     * customer's contacts — and ours, the owner and the people working it.
     *
     * Ours is here because the first version offered only the customer's side,
     * and a lead with nobody named on it yet produced an empty list and a
     * permanently disabled button. There is always somebody on our side of a
     * lead, so there is always a meeting that can be booked.
     *
     * @return list<array{name: string|null, address: string, side: string}>
     */
    private function suggestedAttendees(Lead $lead): array
    {
        $lead->loadMissing(['stakeholders', 'account.contacts', 'owner', 'team.employee']);

        $theirs = collect($lead->stakeholders)
            ->map(fn ($s) => ['name' => $s->name, 'address' => $s->email, 'side' => 'theirs'])
            ->merge(collect($lead->account?->contacts ?? [])
                ->map(fn ($c) => ['name' => $c->full_name, 'address' => $c->email, 'side' => 'theirs']));

        $ours = collect([$lead->owner])
            ->merge(collect($lead->team)->pluck('employee'))
            ->filter()
            ->map(fn ($e) => [
                'name'    => trim($e->first_name . ' ' . $e->last_name),
                'address' => $e->work_email,
                'side'    => 'ours',
            ]);

        return $ours->merge($theirs)
            ->filter(fn ($p) => filter_var($p['address'] ?? null, FILTER_VALIDATE_EMAIL))
            ->unique('address')
            ->values()
            ->all();
    }
}
