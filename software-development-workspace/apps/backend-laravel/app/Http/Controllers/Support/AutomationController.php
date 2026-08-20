<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Support\Automation;
use App\Models\Support\AutomationRun;
use App\Models\Support\Ticket;
use App\Services\Support\AutomationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Writing rules, rehearsing them, and reading what they did.
 */
class AutomationController extends Controller
{
    public function __construct(private readonly AutomationEngine $engine) {}

    public function index(): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        return response()->json([
            'automations' => Automation::withCount('runs')
                ->orderBy('trigger')->orderBy('position')->get(),

            /*
             * The recent firings, across every rule. This is the screen
             * somebody opens when a ticket has done something odd, so it leads
             * with what happened rather than with what the rules say.
             */
            'runs' => AutomationRun::with([
                'automation:id,name,trigger',
                'ticket:id,reference,subject',
            ])->orderByDesc('fired_at')->limit(100)->get(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        $data = $request->validate([
            'id'          => ['nullable', 'integer', 'exists:support_automations,id'],
            'name'        => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:400'],
            'trigger'     => ['required', 'in:created,updated,scheduled'],
            'conditions'  => ['array'],
            'actions'     => ['required', 'array', 'min:1'],
            'position'    => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active'   => ['boolean'],
            'is_dry_run'  => ['boolean'],
            'once_per_ticket' => ['boolean'],
            'cooldown_hours'  => ['nullable', 'integer', 'min:1', 'max:8760'],
        ]);

        /*
         * A scheduled rule with no conditions matches every open ticket every
         * hour. Refused rather than allowed to run once and be noticed
         * afterwards.
         */
        if ($data['trigger'] === Automation::ON_SCHEDULE && empty(array_filter($data['conditions'] ?? []))) {
            return response()->json([
                'message' => 'A scheduled rule needs at least one condition. Without one it matches '
                    . 'every open ticket, every hour.',
            ], 422);
        }

        /*
         * A rule that reaches the customer repeatedly needs a cooldown. Chasing
         * somebody hourly is not a follow-up.
         */
        $reachesOut = array_key_exists('reply', $data['actions']);
        $repeats    = ! ($data['once_per_ticket'] ?? true);

        if ($reachesOut && $repeats && empty($data['cooldown_hours'])) {
            return response()->json([
                'message' => 'A rule that replies to customers more than once needs a cooldown. '
                    . 'Without one this chases the same person every hour.',
            ], 422);
        }

        $automation = Automation::updateOrCreate(
            ['id' => $data['id'] ?? null],
            collect($data)->except('id')->all() + [
                'organization_id' => app('current.organization_id'),
                'created_by'      => $request->user()?->id,
            ],
        );

        return response()->json(['data' => $automation->fresh()], 201);
    }

    /**
     * Rehearse a rule against the tickets it would touch, changing nothing.
     *
     * The point of arming separately. A rule that closes tickets should be
     * readable, arguable and rehearsed before it is allowed near anything.
     */
    public function rehearse(int $automation): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        $rule = Automation::findOrFail($automation);

        $tickets = Ticket::with(['status', 'priority', 'serviceCategory'])
            ->whereHas('status', fn ($q) => $q->whereIn('state', ['open', 'pending']))
            ->limit(500)->get();

        $would = $tickets
            ->filter(fn (Ticket $t) => $this->engine->matches($rule, $t))
            ->map(fn (Ticket $t) => [
                'reference' => $t->reference,
                'subject'   => $t->subject,
                'status'    => $t->status?->name,
                // Whether it would actually act, or is barred by its own guards.
                'would_act' => $rule->mayActOn($t),
            ])
            ->values();

        return response()->json([
            'matched' => $would->count(),
            'acting'  => $would->where('would_act', true)->count(),
            'tickets' => $would->take(50),
            'note'    => $rule->is_dry_run
                ? 'This rule is still a rehearsal — it records what it would do and does nothing.'
                : 'This rule is armed. Matching tickets will be acted on.',
        ]);
    }

    /** What the rules did to one ticket. */
    public function forTicket(int $ticket): JsonResponse
    {
        $this->authorize('view', Ticket::class);

        return response()->json([
            'data' => AutomationRun::where('support_ticket_id', $ticket)
                ->with('automation:id,name,trigger')
                ->orderByDesc('fired_at')->get(),
        ]);
    }
}
