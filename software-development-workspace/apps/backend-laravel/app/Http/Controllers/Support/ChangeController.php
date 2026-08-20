<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Support\Approval;
use App\Models\Support\ApprovalStage;
use App\Models\Support\Change;
use App\Models\Support\Problem;
use App\Models\Support\Ticket;
use App\Models\Support\TicketLink;
use App\Services\Support\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Problems, changes and the sign-off in between.
 */
class ChangeController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Ticket::class);

        return response()->json([
            'problems' => Problem::with(['owner:id,name', 'category:id,name'])
                ->withCount('links')
                ->orderByDesc('id')->limit(100)->get(),

            'changes' => Change::with(['implementer:id,name', 'account:id,name', 'problem:id,reference,title'])
                ->withCount('approvals')
                ->orderByDesc('id')->limit(100)->get(),

            /*
             * The change calendar. Only what is coming, because a calendar
             * showing last month's windows is a list, and the question this
             * answers is "what else is happening that night".
             */
            'calendar' => Change::whereIn('status', [Change::SCHEDULED, Change::APPROVED, Change::IN_PROGRESS])
                ->whereNotNull('window_start')
                ->where('window_start', '>=', now()->subDay())
                ->orderBy('window_start')->limit(50)
                ->get(['id', 'reference', 'title', 'change_type', 'risk', 'status', 'window_start', 'window_end']),

            'stages' => ApprovalStage::with(['approver:id,name'])
                ->orderBy('applies_to')->orderBy('sequence')->get(),

            'my_approvals' => $request->user()
                ? $this->approvals->pendingFor($request->user(), app('current.organization_id'))
                : [],
        ]);
    }

    // ── Problems ──────────────────────────────────────────────────────────────

    public function saveProblem(Request $request): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        $data = $request->validate([
            'id'         => ['nullable', 'integer', 'exists:support_problems,id'],
            'title'      => ['required', 'string', 'max:300'],
            'statement'  => ['nullable', 'string'],
            'root_cause' => ['nullable', 'string'],
            'workaround' => ['nullable', 'string'],
            'status'     => ['nullable', 'in:investigating,known_error,resolved,closed'],
            'owner_id'   => ['nullable', 'integer', 'exists:users,id'],
            'service_category_id' => ['nullable', 'integer', 'exists:support_service_categories,id'],
        ]);

        /*
         * A known error is a promise that a workaround exists. Without one the
         * status says the desk knows what to do and it does not, which is worse
         * than admitting it is still being investigated.
         */
        if (($data['status'] ?? null) === Problem::KNOWN_ERROR && blank($data['workaround'] ?? null)) {
            return response()->json([
                'message' => 'A known error needs a workaround. The status tells the desk there is '
                    . 'something to tell the customer — write it down first.',
            ], 422);
        }

        $orgId = app('current.organization_id');

        $problem = Problem::updateOrCreate(
            ['id' => $data['id'] ?? null],
            collect($data)->except('id')->all() + [
                'organization_id' => $orgId,
                'reference'       => ($data['id'] ?? null)
                    ? Problem::find($data['id'])?->reference
                    : Problem::nextReference($orgId),
                'identified_at'   => now(),
            ],
        );

        return response()->json(['data' => $problem->fresh()], 201);
    }

    /** Say that a ticket is one of the ones this problem accounts for. */
    public function linkTicket(Request $request, int $problem): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        $data = $request->validate([
            'ticket_reference' => ['required', 'string', 'max:24'],
            'relationship'     => ['nullable', 'in:caused_by,resolved_by,related'],
        ]);

        $record = Problem::findOrFail($problem);
        $ticket = Ticket::where('reference', strtoupper(trim($data['ticket_reference'])))->first();

        if (! $ticket) {
            return response()->json(['message' => 'No ticket here has that reference.'], 422);
        }

        TicketLink::updateOrCreate(
            ['support_ticket_id' => $ticket->id, 'support_problem_id' => $record->id],
            ['relationship' => $data['relationship'] ?? TicketLink::CAUSED_BY,
             'linked_by' => $request->user()?->id],
        );

        return response()->json(['data' => $record->fresh()->loadCount('links')]);
    }

    // ── Changes ───────────────────────────────────────────────────────────────

    public function saveChange(Request $request): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        $data = $request->validate([
            'id'                  => ['nullable', 'integer', 'exists:support_changes,id'],
            'title'               => ['required', 'string', 'max:300'],
            'description'         => ['nullable', 'string'],
            'change_type'         => ['required', 'in:standard,normal,emergency'],
            'risk'                => ['required', 'in:low,medium,high'],
            'implementation_plan' => ['nullable', 'string'],
            'rollback_plan'       => ['nullable', 'string'],
            'window_start'        => ['nullable', 'date'],
            'window_end'          => ['nullable', 'date', 'after:window_start'],
            'account_id'          => ['nullable', 'integer', 'exists:accounts,id'],
            'support_problem_id'  => ['nullable', 'integer', 'exists:support_problems,id'],
            'implementer_id'      => ['nullable', 'integer', 'exists:users,id'],
        ]);

        /*
         * "What do we do if this goes wrong" is the question nobody asks at
         * 2am, so the answer has to exist before the window opens. Standard
         * changes are exempt because they are routine by definition.
         */
        if ($data['change_type'] !== Change::STANDARD && blank($data['rollback_plan'] ?? null)) {
            return response()->json([
                'message' => 'A rollback plan is required on anything but a standard change. '
                    . 'If there is genuinely no way back, say that here — it is a fact worth '
                    . 'somebody reading before they approve it.',
            ], 422);
        }

        $orgId = app('current.organization_id');

        $change = Change::updateOrCreate(
            ['id' => $data['id'] ?? null],
            collect($data)->except('id')->all() + [
                'organization_id' => $orgId,
                'reference'       => ($data['id'] ?? null)
                    ? Change::find($data['id'])?->reference
                    : Change::nextReference($orgId),
                'requested_by'    => $request->user()?->id,
            ],
        );

        return response()->json(['data' => $change->fresh()], 201);
    }

    /** Ask for sign-off. */
    public function submit(Request $request, int $change): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        $record = Change::findOrFail($change);

        try {
            $stages = $this->approvals->requestForChange($record, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data'    => $record->fresh('approvals'),
            'message' => $stages === 0
                ? 'Standard change — pre-approved, no sign-off needed.'
                : "Sent to {$stages} " . ($stages === 1 ? 'approver.' : 'approvers, in order.'),
        ]);
    }

    public function decide(Request $request, int $approval): JsonResponse
    {
        $this->authorize('approveChange', Ticket::class);

        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'comment'  => ['nullable', 'string', 'max:2000'],
        ]);

        $record = Approval::findOrFail($approval);

        try {
            $decided = $this->approvals->decide(
                $record, $data['decision'], $data['comment'] ?? null, $request->user(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $decided]);
    }

    /** What actually happened, written afterwards. */
    public function recordOutcome(Request $request, int $change): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        $data = $request->validate([
            'status'  => ['required', 'in:in_progress,implemented,failed,cancelled'],
            'outcome' => ['nullable', 'string', 'max:4000'],
        ]);

        $record = Change::findOrFail($change);

        /*
         * A failed change with no note is the one somebody repeats next
         * quarter, having found no record of why it went wrong.
         */
        if ($data['status'] === Change::FAILED && blank($data['outcome'] ?? null)) {
            return response()->json([
                'message' => 'Say what went wrong. A failed change with no note is the one somebody '
                    . 'repeats next quarter.',
            ], 422);
        }

        $record->forceFill([
            'status'         => $data['status'],
            'outcome'        => $data['outcome'] ?? $record->outcome,
            'implemented_at' => $data['status'] === Change::IMPLEMENTED ? now() : $record->implemented_at,
        ])->save();

        return response()->json(['data' => $record->fresh()]);
    }

    public function saveStage(Request $request): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        $data = $request->validate([
            'id'          => ['nullable', 'integer', 'exists:support_approval_stages,id'],
            'stage'       => ['required', 'string', 'max:60'],
            'sequence'    => ['required', 'integer', 'min:1', 'max:99'],
            'applies_to'  => ['required', 'in:change,service_request'],
            'risk'        => ['nullable', 'in:low,medium,high'],
            'support_service_id' => ['nullable', 'integer', 'exists:support_services,id'],
            'approver_id' => ['nullable', 'integer', 'exists:users,id'],
            'is_active'   => ['boolean'],
        ]);

        $stage = ApprovalStage::updateOrCreate(
            ['id' => $data['id'] ?? null],
            collect($data)->except('id')->all() + ['organization_id' => app('current.organization_id')],
        );

        return response()->json(['data' => $stage->fresh('approver')], 201);
    }
}
