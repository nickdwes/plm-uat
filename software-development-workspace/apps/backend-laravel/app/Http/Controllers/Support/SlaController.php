<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Account\AccountContract;
use App\Models\Support\Calendar;
use App\Models\Support\CalendarHoliday;
use App\Models\Support\CalendarWindow;
use App\Models\Support\Sla;
use App\Models\Support\SlaTarget;
use App\Models\Support\Ticket;
use App\Models\Support\TicketPriority;
use App\Services\Support\SupportDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Service levels and the calendars they are measured against.
 *
 * Everything here is configuration that silently changes what every open
 * ticket is judged by, which is why it sits behind its own permission rather
 * than the one that lets an agent answer a ticket.
 */
class SlaController extends Controller
{
    public function __construct(private readonly SupportDefaults $defaults) {}

    /** Everything a service-level screen needs, in one call. */
    public function index(): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        $orgId = app('current.organization_id');
        $this->defaults->ensureFor($orgId);
        $this->defaults->defaultCalendar($orgId);

        return response()->json([
            'calendars' => Calendar::with(['windows', 'holidays' => fn ($q) => $q->orderBy('observed_on')])
                ->orderByDesc('is_default')->get()
                ->map(fn (Calendar $c) => [
                    'id'         => $c->id,
                    'name'       => $c->name,
                    'timezone'   => $c->timezone,
                    'is_default' => $c->is_default,
                    'windows'    => $c->windows->map(fn ($w) => [
                        'id' => $w->id, 'weekday' => $w->weekday,
                        'opens_at' => substr($w->opens_at, 0, 5),
                        'closes_at' => substr($w->closes_at, 0, 5),
                    ])->sortBy('weekday')->values(),
                    'holidays'   => $c->holidays->map(fn ($h) => [
                        'id' => $h->id,
                        'observed_on' => $h->observed_on->toDateString(),
                        'name' => $h->name,
                    ])->values(),
                ]),

            'slas' => Sla::with(['targets.priority', 'calendar:id,name', 'contract:id,name'])
                ->orderByDesc('is_default')->get()
                ->map(fn (Sla $s) => [
                    'id'         => $s->id,
                    'name'       => $s->name,
                    'is_default' => $s->is_default,
                    'is_active'  => $s->is_active,
                    'calendar'   => $s->calendar?->name,
                    'calendar_id' => $s->support_calendar_id,
                    'contract'   => $s->contract?->name,
                    'contract_id' => $s->account_contract_id,
                    'targets'    => $s->targets->map(fn (SlaTarget $t) => [
                        'id'          => $t->id,
                        'priority_id' => $t->priority_id,
                        'priority'    => $t->priority?->name,
                        'level'       => $t->priority?->level,
                        'response_minutes'   => $t->response_minutes,
                        'resolution_minutes' => $t->resolution_minutes,
                        'hod_escalation_minutes' => $t->hod_escalation_minutes,
                        'cto_escalation_minutes' => $t->cto_escalation_minutes,
                        'ceo_escalation_minutes' => $t->ceo_escalation_minutes,
                    ])->sortBy('level')->values(),
                ]),

            'priorities' => TicketPriority::orderBy('level')->get(['id', 'name', 'level', 'colour']),
            'contracts'  => AccountContract::with('account:id,name')->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'account' => $c->account?->name]),
        ]);
    }

    // ── Calendars ─────────────────────────────────────────────────────────────

    public function storeCalendar(Request $request): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:120'],
            'timezone' => ['nullable', 'string', 'max:64'],
            /*
             * Windows come in whole, because a calendar edited a day at a time
             * spends the interval half-applied — and every live ticket is being
             * measured against it meanwhile.
             */
            'windows'             => ['array'],
            'windows.*.weekday'   => ['required', 'integer', 'between:0,6'],
            'windows.*.opens_at'  => ['required', 'date_format:H:i'],
            'windows.*.closes_at' => ['required', 'date_format:H:i'],
        ]);

        $calendar = DB::transaction(function () use ($data) {
            $calendar = Calendar::create([
                'organization_id' => app('current.organization_id'),
                'name'            => $data['name'],
                'timezone'        => $data['timezone'] ?? 'Africa/Nairobi',
            ]);

            foreach ($data['windows'] ?? [] as $window) {
                CalendarWindow::create([
                    'support_calendar_id' => $calendar->id,
                    'weekday'   => $window['weekday'],
                    'opens_at'  => $window['opens_at'] . ':00',
                    'closes_at' => $window['closes_at'] . ':00',
                ]);
            }

            return $calendar;
        });

        return response()->json(['data' => $calendar->load('windows')], 201);
    }

    /** Replace a calendar's week in one write, for the reason above. */
    public function updateWindows(Request $request, int $calendar): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        $data = $request->validate([
            'windows'             => ['present', 'array'],
            'windows.*.weekday'   => ['required', 'integer', 'between:0,6'],
            'windows.*.opens_at'  => ['required', 'date_format:H:i'],
            'windows.*.closes_at' => ['required', 'date_format:H:i'],
        ]);

        $record = Calendar::findOrFail($calendar);

        DB::transaction(function () use ($record, $data) {
            $record->windows()->delete();

            foreach ($data['windows'] as $window) {
                CalendarWindow::create([
                    'support_calendar_id' => $record->id,
                    'weekday'   => $window['weekday'],
                    'opens_at'  => $window['opens_at'] . ':00',
                    'closes_at' => $window['closes_at'] . ':00',
                ]);
            }
        });

        return response()->json(['data' => $record->fresh('windows')]);
    }

    public function storeHoliday(Request $request, int $calendar): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        $data = $request->validate([
            'observed_on' => ['required', 'date'],
            'name'        => ['required', 'string', 'max:120'],
        ]);

        $record = Calendar::findOrFail($calendar);

        $holiday = CalendarHoliday::updateOrCreate(
            ['support_calendar_id' => $record->id, 'observed_on' => $data['observed_on']],
            ['name' => $data['name']],
        );

        return response()->json(['data' => $holiday], 201);
    }

    public function destroyHoliday(int $calendar, int $holiday): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        Calendar::findOrFail($calendar);
        CalendarHoliday::where('support_calendar_id', $calendar)->findOrFail($holiday)->delete();

        return response()->json(['deleted' => true]);
    }

    // ── Service levels ────────────────────────────────────────────────────────

    public function storeSla(Request $request): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        $data = $request->validate([
            'name'                => ['required', 'string', 'max:160'],
            'account_contract_id' => ['nullable', 'integer', 'exists:account_contracts,id'],
            'support_calendar_id' => ['nullable', 'integer', 'exists:support_calendars,id'],
            'is_default'          => ['boolean'],
        ]);

        $sla = DB::transaction(function () use ($data) {
            /*
             * One fallback only. Two rows both claiming to be the default is a
             * silent coin toss over what every uncontracted ticket is promised.
             */
            if ($data['is_default'] ?? false) {
                Sla::where('is_default', true)->update(['is_default' => false]);
            }

            return Sla::create($data + ['organization_id' => app('current.organization_id')]);
        });

        return response()->json(['data' => $sla], 201);
    }

    public function updateSla(Request $request, int $sla): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        $data = $request->validate([
            'name'                => ['sometimes', 'string', 'max:160'],
            'account_contract_id' => ['nullable', 'integer', 'exists:account_contracts,id'],
            'support_calendar_id' => ['nullable', 'integer', 'exists:support_calendars,id'],
            'is_default'          => ['boolean'],
            'is_active'           => ['boolean'],
        ]);

        $record = Sla::findOrFail($sla);

        DB::transaction(function () use ($record, $data) {
            if ($data['is_default'] ?? false) {
                Sla::where('id', '!=', $record->id)->update(['is_default' => false]);
            }

            $record->update($data);
        });

        return response()->json(['data' => $record->fresh()]);
    }

    /**
     * Set the numbers for one priority.
     *
     * Existing clocks are untouched. A target changed today must not restate
     * what a ticket answered last week was judged against — the ticket carries
     * its own copy for exactly that reason.
     */
    public function saveTarget(Request $request, int $sla): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        $data = $request->validate([
            'priority_id'        => ['required', 'integer', 'exists:support_ticket_priorities,id'],
            'response_minutes'   => ['required', 'integer', 'min:1', 'max:525600'],
            'resolution_minutes' => ['required', 'integer', 'min:1', 'max:525600'],
            'hod_escalation_minutes' => ['nullable', 'integer', 'min:1', 'max:525600'],
            'cto_escalation_minutes' => ['nullable', 'integer', 'min:1', 'max:525600'],
            'ceo_escalation_minutes' => ['nullable', 'integer', 'min:1', 'max:525600'],
        ]);

        $record = Sla::findOrFail($sla);

        // A resolution target inside the response target is almost always a
        // slip, and it would make every ticket breach resolution first.
        if ($data['resolution_minutes'] < $data['response_minutes']) {
            return response()->json([
                'message' => 'The resolution target is shorter than the response target, which would '
                    . 'breach resolution before anybody had answered. Check the two figures.',
            ], 422);
        }

        $target = SlaTarget::updateOrCreate(
            ['support_sla_id' => $record->id, 'priority_id' => $data['priority_id']],
            $data,
        );

        return response()->json(['data' => $target->load('priority')], 201);
    }

    public function destroyTarget(int $sla, int $target): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        Sla::findOrFail($sla);
        SlaTarget::where('support_sla_id', $sla)->findOrFail($target)->delete();

        return response()->json(['deleted' => true]);
    }
}
