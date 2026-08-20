<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Support\PortalToken;
use App\Models\Support\Service;
use App\Models\Support\Survey;
use App\Models\Support\Ticket;
use App\Models\Support\TicketAnswer;
use App\Models\Support\TicketMessage;
use App\Services\Support\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a customer can see and do without an account.
 *
 * Unauthenticated by design — the plan's recommendation, adopted: email-first,
 * read-only by signed link, full accounts only for clients who ask. A portal
 * login is an identity surface for people outside the company, and none of it
 * is worth building before somebody has asked for one.
 *
 * ── Nothing here returns a model ──
 *
 * Every response is assembled field by field. Returning an Eloquent model from
 * a public endpoint means one `$fillable` change away from publishing an
 * internal note, an assignee's name or a contract id to a customer — and the
 * change that does it will look unrelated. The one rule this file exists to
 * enforce is that the customer sees public messages and nothing else, so that
 * rule is written out rather than inherited.
 */
class PortalController extends Controller
{
    public function __construct(private readonly TicketService $tickets) {}

    /** One ticket, as its requester sees it. */
    public function ticket(Request $request): JsonResponse
    {
        $token = PortalToken::resolve($request->query('token'));

        if (! $token || ! $token->support_ticket_id) {
            return $this->refuse();
        }

        $token->touchUse();

        $ticket = Ticket::withoutGlobalScope('organization')
            ->with(['status:id,name,state', 'answers'])
            ->find($token->support_ticket_id);

        if (! $ticket) {
            return $this->refuse();
        }

        return response()->json(['data' => $this->present($ticket)]);
    }

    /** Everything this contact has raised. */
    public function tickets(Request $request): JsonResponse
    {
        $token = PortalToken::resolve($request->query('token'));

        if (! $token || ! $token->account_contact_id) {
            return $this->refuse();
        }

        $token->touchUse();

        /*
         * Matched on the contact *and* the address the link was issued to. A
         * contact record edited to another person's email must not hand them
         * the previous holder's history.
         */
        $tickets = Ticket::withoutGlobalScope('organization')
            ->where('organization_id', $token->organization_id)
            ->where('account_contact_id', $token->account_contact_id)
            ->whereRaw('lower(requester_email) = ?', [mb_strtolower($token->email)])
            ->with('status:id,name,state')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $tickets->map(fn (Ticket $t) => [
                'reference' => $t->reference,
                'subject'   => $t->subject,
                'status'    => $t->status?->name,
                'state'     => $t->status?->state,
                'opened_at' => $t->opened_at?->toDateTimeString(),
                'resolved_at' => $t->resolved_at?->toDateTimeString(),
            ]),
        ]);
    }

    /** Reply to your own ticket. */
    public function reply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'body'  => ['required', 'string', 'max:10000'],
        ]);

        $token = PortalToken::resolve($data['token']);

        if (! $token || ! $token->support_ticket_id) {
            return $this->refuse();
        }

        $ticket = Ticket::withoutGlobalScope('organization')->find($token->support_ticket_id);

        if (! $ticket) {
            return $this->refuse();
        }

        $token->touchUse();

        /*
         * Always public and never from an agent, whatever else is true. This is
         * the one place a customer writes into the system, and a reply
         * mislabelled as an agent's would set a first-response time that never
         * happened.
         */
        $this->tickets->reply($ticket, [
            'body'          => $data['body'],
            'visibility'    => TicketMessage::VISIBILITY_PUBLIC,
            'author_name'   => $ticket->requester_name,
            'author_email'  => $token->email,
            'source'        => 'portal',
            'is_from_agent' => false,
        ]);

        return response()->json(['data' => $this->present($ticket->fresh(['status', 'answers']))], 201);
    }

    // ── The catalogue ─────────────────────────────────────────────────────────

    public function catalogue(Request $request): JsonResponse
    {
        $token = PortalToken::resolve($request->query('token'));

        if (! $token) {
            return $this->refuse();
        }

        $services = Service::withoutGlobalScope('organization')
            ->where('organization_id', $token->organization_id)
            ->where('is_active', true)
            ->orderBy('position')->orderBy('name')
            ->get();

        return response()->json([
            'data' => $services->map(fn (Service $s) => [
                'slug'    => $s->slug,
                'name'    => $s->name,
                'summary' => $s->summary,
                'icon'    => $s->icon,
                'fields'  => $s->fields ?? [],
                // Said up front, because "why has nothing happened" is the
                // question an unexplained approval step generates.
                'needs_approval' => $s->needs_approval,
            ]),
        ]);
    }

    /** Raise a request from the catalogue. */
    public function submit(Request $request): JsonResponse
    {
        $base = $request->validate([
            'token'   => ['required', 'string'],
            'service' => ['required', 'string', 'max:160'],
            'summary' => ['nullable', 'string', 'max:300'],
        ]);

        $token = PortalToken::resolve($base['token']);

        if (! $token) {
            return $this->refuse();
        }

        $service = Service::withoutGlobalScope('organization')
            ->where('organization_id', $token->organization_id)
            ->where('slug', $base['service'])
            ->where('is_active', true)
            ->first();

        if (! $service) {
            return response()->json(['message' => 'That service is not available.'], 422);
        }

        // Rules come from the form definition rather than a second copy, so a
        // required field cannot be required on screen and optional here.
        $answers = $request->validate($service->rules())['answers'] ?? [];

        $contact = $token->contact;

        $ticket = $this->tickets->open($token->organization_id, [
            // A nullable field that was not sent is absent, not null.
            'subject'             => ($base['summary'] ?? null) ?: $service->name,
            'body'                => $this->describe($service, $answers),
            'requester_name'      => trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: null,
            'requester_email'     => $token->email,
            'account_id'          => $contact?->account_id,
            'account_contact_id'  => $contact?->id,
            'ticket_type_id'      => $service->ticket_type_id,
            'service_category_id' => $service->service_category_id,
            'impact_id'           => $service->default_impact_id,
            'urgency_id'          => $service->default_urgency_id,
            'source'              => 'portal',
        ]);

        $ticket->forceFill(['support_service_id' => $service->id])->save();

        foreach ($service->fields ?? [] as $i => $field) {
            $key = $field['key'] ?? null;

            if (! $key) {
                continue;
            }

            TicketAnswer::create([
                'support_ticket_id'  => $ticket->id,
                'support_service_id' => $service->id,
                'field_key' => $key,
                // The label as it stood, so editing the question later does not
                // rewrite what this person was asked.
                'label'     => $field['label'] ?? $key,
                'value'     => $answers[$key] ?? null,
                'position'  => $i,
            ]);
        }

        /*
         * A service marked as needing approval now actually raises one. It was
         * a flag the portal told people about and nothing acted on — which is
         * exactly what "Awaiting Approval" was in the old module.
         */
        if ($service->needs_approval) {
            app(\App\Services\Support\ApprovalService::class)
                ->requestForTicket($ticket, $service->id);
        }

        // Their own link to the ticket they just raised.
        $issued = PortalToken::issue([
            'organization_id'   => $token->organization_id,
            'support_ticket_id' => $ticket->id,
            'email'             => $token->email,
        ]);

        return response()->json([
            'reference' => $ticket->reference,
            'token'     => $issued['token'],
            'message'   => "Raised as {$ticket->reference}."
                . ($service->needs_approval ? ' It needs approval before work starts.' : ''),
        ], 201);
    }

    // ── Help before a ticket ──────────────────────────────────────────────────

    /**
     * Search the published, customer-visible articles.
     *
     * Offered before the catalogue form, because the cheapest ticket is the one
     * nobody needed to raise. Internal articles are excluded by the query, not
     * filtered afterwards — a filter is one refactor away from being dropped.
     */
    public function help(Request $request, \App\Services\Support\KnowledgeBase $kb): JsonResponse
    {
        $token = PortalToken::resolve($request->query('token'));

        if (! $token) {
            return $this->refuse();
        }

        $phrase = trim((string) $request->query('q'));

        return response()->json([
            'data' => $phrase === ''
                ? []
                : $kb->search($token->organization_id, $phrase, customerOnly: true, limit: 6),
        ]);
    }

    /** One article, as a customer reads it. */
    public function article(Request $request): JsonResponse
    {
        $token = PortalToken::resolve($request->query('token'));

        if (! $token) {
            return $this->refuse();
        }

        $article = \App\Models\Support\Article::withoutGlobalScope('organization')
            ->where('organization_id', $token->organization_id)
            ->where('slug', $request->query('slug'))
            ->forCustomers()
            ->first();

        if (! $article) {
            return $this->refuse();
        }

        $article->increment('view_count');
        $article->forceFill(['last_viewed_at' => now()])->save();

        return response()->json([
            'data' => [
                'slug'    => $article->slug,
                'title'   => $article->title,
                'summary' => $article->summary,
                'body'    => $article->body,
            ],
        ]);
    }

    /**
     * Was it any use?
     *
     * Two counters and no comment box. A customer who wants to say more raises
     * a ticket, and a free-text field on an anonymous link is a moderation
     * problem nobody asked for.
     */
    public function rateArticle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'   => ['required', 'string'],
            'slug'    => ['required', 'string', 'max:220'],
            'helpful' => ['required', 'boolean'],
        ]);

        $token = PortalToken::resolve($data['token']);

        if (! $token) {
            return $this->refuse();
        }

        $article = \App\Models\Support\Article::withoutGlobalScope('organization')
            ->where('organization_id', $token->organization_id)
            ->where('slug', $data['slug'])
            ->forCustomers()
            ->first();

        if (! $article) {
            return $this->refuse();
        }

        $article->increment($data['helpful'] ? 'helpful_count' : 'unhelpful_count');

        return response()->json(['message' => 'Thank you.']);
    }

    // ── The survey ────────────────────────────────────────────────────────────

    public function survey(Request $request): JsonResponse
    {
        $survey = Survey::resolve($request->query('token'));

        if (! $survey) {
            return $this->refuse();
        }

        $ticket = $survey->ticket;

        return response()->json([
            'data' => [
                'reference' => $ticket?->reference,
                'subject'   => $ticket?->subject,
                'answered'  => $survey->isAnswered(),
                'rating'    => $survey->rating,
            ],
        ]);
    }

    public function respond(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'          => ['required', 'string'],
            'rating'         => ['required', 'integer', 'between:1,5'],
            'satisfaction'   => ['nullable', 'in:yes,partly,no'],
            'timeliness'     => ['nullable', 'in:yes,partly,no'],
            'resolved_fully' => ['nullable', 'in:yes,partly,no'],
            'comment'        => ['nullable', 'string', 'max:2000'],
        ]);

        $survey = Survey::resolve($data['token']);

        if (! $survey) {
            return $this->refuse();
        }

        /*
         * Answered once. Letting somebody revise it sounds generous and turns a
         * satisfaction figure into something that moves after the fact, which
         * is exactly what it must not do.
         */
        if ($survey->isAnswered()) {
            return response()->json([
                'message' => 'Thank you — this survey has already been answered.',
            ], 409);
        }

        $survey->forceFill(collect($data)->except('token')->all() + ['responded_at' => now()])->save();

        return response()->json(['message' => 'Thank you.']);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The customer's view of a ticket, assembled field by field.
     *
     * Public messages only. Internal notes are the desk's working record —
     * supplier prices, what has been ruled out, occasionally an opinion about
     * the customer — and one careless serialisation publishes all of it.
     */
    private function present(Ticket $ticket): array
    {
        return [
            'reference'   => $ticket->reference,
            'subject'     => $ticket->subject,
            'status'      => $ticket->status?->name,
            'state'       => $ticket->status?->state,
            'opened_at'   => $ticket->opened_at?->toDateTimeString(),
            'resolved_at' => $ticket->resolved_at?->toDateTimeString(),

            'answers' => $ticket->answers->sortBy('position')->values()
                ->map(fn (TicketAnswer $a) => ['label' => $a->label, 'value' => $a->value]),

            'messages' => $ticket->messages()
                ->where('visibility', TicketMessage::VISIBILITY_PUBLIC)
                ->orderBy('id')
                ->get()
                ->map(fn (TicketMessage $m) => [
                    // Whether it came from us, not who — an agent's name is
                    // ours to share deliberately, not by default.
                    'from'       => $m->is_from_agent ? 'Support' : ($m->author_name ?: 'You'),
                    'from_us'    => $m->is_from_agent,
                    'body'       => $m->body,
                    'created_at' => $m->created_at?->toDateTimeString(),
                ]),
        ];
    }

    private function describe(Service $service, array $answers): string
    {
        $lines = ["Requested from the catalogue: {$service->name}."];

        foreach ($service->fields ?? [] as $field) {
            $key = $field['key'] ?? null;

            if ($key && filled($answers[$key] ?? null)) {
                $lines[] = ($field['label'] ?? $key) . ': ' . $answers[$key];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * One answer for expired, revoked, wrong and never-existed.
     *
     * Telling a caller which of those it was is free help to somebody guessing.
     */
    private function refuse(): JsonResponse
    {
        return response()->json([
            'message' => 'This link is no longer valid. Ask us for a new one and we will send it over.',
        ], 404);
    }
}
