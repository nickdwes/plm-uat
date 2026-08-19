<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Support\Mailbox;
use App\Models\Support\SenderRule;
use App\Models\Support\Ticket;
use App\Models\Support\TicketEmail;
use App\Services\Support\GraphMailClient;
use App\Services\Support\InboundMailProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Setting up the mailbox the desk watches, and dealing with what it holds back.
 *
 * The quarantine list is the part that matters day to day. Mail from an address
 * nobody recognises is held rather than discarded or bounced, and this is where
 * somebody looks at it, releases it into a ticket, and — usually in the same
 * motion — adds the rule that stops it being held next time.
 */
class MailboxController extends Controller
{
    public function __construct(
        private readonly GraphMailClient $graph,
        private readonly InboundMailProcessor $processor,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        return response()->json([
            'data' => Mailbox::with('connection:id,name')->get()->map(fn (Mailbox $m) => [
                'id'         => $m->id,
                'address'    => $m->address,
                'is_active'  => $m->is_active,
                'auto_acknowledge' => $m->auto_acknowledge,
                'connection' => $m->connection?->name,
                'subscribed' => $m->subscription_id !== null,
                'expires_at' => $m->subscription_expires_at?->toDateTimeString(),
                'needs_renewal'     => $m->needsRenewal(),
                'last_delivered_at' => $m->last_delivered_at?->toDateTimeString(),
                'last_error'        => $m->last_error,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        $data = $request->validate([
            'address'                   => ['required', 'email', 'max:200'],
            'display_name'              => ['nullable', 'string', 'max:160'],
            'integration_connection_id' => ['required', 'integer', 'exists:integration_connections,id'],
            'default_type_id'           => ['nullable', 'integer', 'exists:support_ticket_types,id'],
            'auto_acknowledge'          => ['boolean'],
        ]);

        $mailbox = Mailbox::create($data + [
            'organization_id' => app('current.organization_id'),
            // Minted here and never shown again; it is what the webhook checks.
            'client_state'    => Str::random(48),
        ]);

        return response()->json(['data' => $mailbox], 201);
    }

    /**
     * Ask Graph to start notifying us.
     *
     * Separate from creating the mailbox so a failed subscription — wrong
     * permissions, unreachable webhook — leaves a mailbox that can be fixed and
     * retried rather than nothing at all.
     */
    public function subscribe(Request $request, int $mailbox): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        $record = Mailbox::findOrFail($mailbox);
        $url    = rtrim(config('app.url'), '/') . '/api/v1/support/mail/webhook';

        try {
            $result = $this->graph->subscribe($record, $url, (string) $record->client_state);
        } catch (Throwable $e) {
            $record->forceFill(['last_error' => substr($e->getMessage(), 0, 480)])->save();

            return response()->json([
                'message' => 'Graph refused the subscription. The usual causes are the app '
                    . 'lacking Mail.Read on this mailbox, or the notification URL not being '
                    . 'reachable from the internet.',
                'detail'  => substr($e->getMessage(), 0, 480),
                'notification_url' => $url,
            ], 422);
        }

        $record->forceFill([
            'subscription_id'         => $result['id'] ?? null,
            'subscription_expires_at' => isset($result['expirationDateTime'])
                ? \Carbon\Carbon::parse($result['expirationDateTime']) : null,
            'last_error' => null,
        ])->save();

        return response()->json(['data' => $record->fresh()]);
    }

    /** Mail the desk is holding, and why. */
    public function quarantine(): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        return response()->json([
            'data' => TicketEmail::whereNotNull('quarantine_reason')
                ->whereNull('released_at')
                ->orderByDesc('received_at')
                ->limit(200)
                ->get(['id', 'from_name', 'from_email', 'subject', 'body_preview',
                       'received_at', 'quarantine_reason']),
        ]);
    }

    /**
     * Let one through, optionally trusting the sender from now on.
     *
     * The rule is offered here because this is the moment somebody has the
     * context to decide it. A whitelist maintained on a separate screen is the
     * whitelist that stays empty — which is exactly what happened to the old
     * module's.
     */
    public function release(Request $request, int $email): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        $data = $request->validate([
            'trust' => ['nullable', 'in:address,domain'],
        ]);

        $record = TicketEmail::findOrFail($email);
        $result = $this->processor->release($record);

        if (($data['trust'] ?? null) && $record->from_email) {
            $value = $data['trust'] === 'domain'
                ? substr(strrchr($record->from_email, '@'), 1)
                : $record->from_email;

            SenderRule::updateOrCreate(
                [
                    'organization_id' => app('current.organization_id'),
                    'kind'            => $data['trust'],
                    'value'           => mb_strtolower($value),
                ],
                ['action' => SenderRule::ACTION_ALLOW, 'created_by' => $request->user()?->id],
            );
        }

        return response()->json([
            'status' => $result['status'],
            'ticket' => $result['ticket'],
        ]);
    }

    public function rules(): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        return response()->json([
            'data' => SenderRule::orderBy('kind')->orderBy('value')
                ->get(['id', 'kind', 'value', 'action', 'note']),
        ]);
    }

    public function storeRule(Request $request): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        $data = $request->validate([
            'kind'   => ['required', 'in:domain,address'],
            'value'  => ['required', 'string', 'max:200'],
            'action' => ['required', 'in:allow,block'],
            'note'   => ['nullable', 'string', 'max:200'],
        ]);

        $rule = SenderRule::updateOrCreate(
            [
                'organization_id' => app('current.organization_id'),
                'kind'            => $data['kind'],
                'value'           => mb_strtolower(trim($data['value'])),
            ],
            ['action' => $data['action'], 'note' => $data['note'] ?? null,
             'created_by' => $request->user()?->id],
        );

        return response()->json(['data' => $rule], 201);
    }

    public function destroyRule(int $rule): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        SenderRule::findOrFail($rule)->delete();

        return response()->json(['deleted' => true]);
    }
}
