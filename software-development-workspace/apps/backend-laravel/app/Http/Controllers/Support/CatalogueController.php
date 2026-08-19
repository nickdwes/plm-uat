<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Support\PortalToken;
use App\Models\Support\Service;
use App\Models\Support\Survey;
use App\Models\Support\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The desk's side of the portal: what customers can ask for, and letting them in.
 */
class CatalogueController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Ticket::class);

        return response()->json([
            'services' => Service::with(['category:id,name', 'type:id,name'])
                ->orderBy('position')->orderBy('name')->get(),

            'surveys' => [
                'sent'      => Survey::count(),
                'answered'  => Survey::whereNotNull('responded_at')->count(),
                'average'   => round((float) Survey::whereNotNull('rating')->avg('rating'), 2),
                'recent'    => Survey::whereNotNull('responded_at')
                    ->with('ticket:id,reference,subject')
                    ->orderByDesc('responded_at')->limit(20)
                    ->get(['id', 'support_ticket_id', 'rating', 'satisfaction',
                           'timeliness', 'resolved_fully', 'comment', 'responded_at']),
            ],
        ]);
    }

    public function saveService(Request $request): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        $data = $request->validate([
            'id'                  => ['nullable', 'integer', 'exists:support_services,id'],
            'name'                => ['required', 'string', 'max:160'],
            'summary'             => ['nullable', 'string', 'max:300'],
            'description'         => ['nullable', 'string'],
            'ticket_type_id'      => ['nullable', 'integer', 'exists:support_ticket_types,id'],
            'service_category_id' => ['nullable', 'integer', 'exists:support_service_categories,id'],
            'default_impact_id'   => ['nullable', 'integer', 'exists:support_ticket_impacts,id'],
            'default_urgency_id'  => ['nullable', 'integer', 'exists:support_ticket_urgencies,id'],
            'needs_approval'      => ['boolean'],
            'is_active'           => ['boolean'],
            'fields'              => ['array'],
            'fields.*.key'        => ['required', 'string', 'max:80'],
            'fields.*.label'      => ['required', 'string', 'max:200'],
            'fields.*.type'       => ['required', 'in:text,textarea,date,number,email,select'],
            'fields.*.required'   => ['boolean'],
            'fields.*.options'    => ['nullable', 'array'],
        ]);

        /*
         * Keys have to be unique within a form, or two questions write to one
         * answer and the second silently wins.
         */
        $keys = collect($data['fields'] ?? [])->pluck('key');

        if ($keys->count() !== $keys->unique()->count()) {
            return response()->json([
                'message' => 'Two questions share a key. Each needs its own, or one answer '
                    . 'overwrites the other.',
            ], 422);
        }

        $service = Service::updateOrCreate(
            ['id' => $data['id'] ?? null],
            collect($data)->except('id')->all() + [
                'organization_id' => app('current.organization_id'),
                'slug'            => \Str::slug($data['name']),
            ],
        );

        return response()->json(['data' => $service], 201);
    }

    /**
     * A link to this ticket, for the person who raised it.
     *
     * The plaintext is returned once and never stored. Sending it is left to
     * whoever asked for it — an agent pasting it into a reply they are already
     * writing beats a second email the customer has to reconcile.
     */
    public function portalLink(Request $request, int $ticket): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        $record = Ticket::findOrFail($ticket);

        if (blank($record->requester_email)) {
            return response()->json([
                'message' => 'This ticket has no requester email, so there is nobody to send a link to.',
            ], 422);
        }

        $issued = PortalToken::issue([
            'organization_id'   => $record->organization_id,
            'support_ticket_id' => $record->id,
            'email'             => $record->requester_email,
        ]);

        return response()->json([
            'url'        => rtrim(config('app.frontend_url', config('app.url')), '/')
                . '/#/portal/ticket?token=' . $issued['token'],
            'expires_at' => $issued['record']->expires_at->toDateTimeString(),
            'note'       => 'Shown once. It is stored hashed, so it cannot be shown again — '
                . 'issue another if it is lost.',
        ], 201);
    }

    /** A link to everything this contact has raised. */
    public function accountLink(Request $request): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        $data = $request->validate([
            'account_contact_id' => ['required', 'integer', 'exists:account_contacts,id'],
        ]);

        $contact = \App\Models\Account\AccountContact::findOrFail($data['account_contact_id']);

        if (blank($contact->email)) {
            return response()->json(['message' => 'That contact has no email address.'], 422);
        }

        $issued = PortalToken::issue([
            'organization_id'    => app('current.organization_id'),
            'account_contact_id' => $contact->id,
            'email'              => $contact->email,
        ]);

        return response()->json([
            'url'        => rtrim(config('app.frontend_url', config('app.url')), '/')
                . '/#/portal/tickets?token=' . $issued['token'],
            'expires_at' => $issued['record']->expires_at->toDateTimeString(),
        ], 201);
    }

    /**
     * Ask how it went.
     *
     * Only on a resolved ticket. Asking somebody to rate work that is still
     * open gets a rating of the wait, which is not what the question is for.
     */
    public function sendSurvey(Request $request, int $ticket): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        $record = Ticket::with('status')->findOrFail($ticket);

        if (! $record->status?->isTerminal()) {
            return response()->json([
                'message' => 'Resolve the ticket first. A survey on open work rates the wait.',
            ], 422);
        }

        $issued = Survey::issue($record, $record->requester_email);

        return response()->json([
            'url' => rtrim(config('app.frontend_url', config('app.url')), '/')
                . '/#/portal/survey?token=' . $issued['token'],
            'expires_at' => $issued['record']->expires_at?->toDateTimeString(),
        ], 201);
    }
}
