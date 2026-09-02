<?php

namespace App\Http\Controllers\Pipeline;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pipeline\StoreOpportunityRequest;
use App\Http\Resources\Pipeline\OpportunityResource;
use App\Models\Pipeline\Opportunity;
use App\Models\Pipeline\OpportunityAssignee;
use App\Models\Pipeline\OpportunityPhase;
use App\Services\Pipeline\PipelineService;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class OpportunityController extends Controller
{
    private const DETAIL = ['account', 'phase', 'owner', 'presaleLead', 'assignees.employee', 'lead'];

    public function __construct(private readonly PipelineService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Opportunity::class);

        $q = Opportunity::query()->with(['account', 'phase', 'owner', 'assignees.employee']);

        if (! $request->user()->hasPermission(Permission::PIPELINE_VIEW)) {
            $employeeId = $request->user()->employeeId();
            $q->where(fn ($w) => $w->where('owner_id', $employeeId)
                ->orWhere('presale_lead_id', $employeeId)
                ->orWhereHas('assignees', fn ($a) => $a->where('employee_id', $employeeId)));
        }

        if ($accountId = $request->integer('account_id')) {
            $q->where('account_id', $accountId);
        }

        /*
         * Open, won or lost — asked of the phase, so the answer cannot
         * contradict which phase the deal is in.
         *
         * A deal with **no** phase counts as open, which is the same answer
         * `Opportunity::is_open` gives (`outcome === null`, and a missing phase
         * has no outcome). It did not: `whereHas` needs the phase to exist, so
         * a phaseless deal was open to the model and invisible to this list.
         * Every one of the 130 imported deals was in that state — the customer
         * page reported "17 active deals" while this page reported none.
         *
         * Won and lost stay strict: claiming an outcome requires a phase that
         * says so.
         */
        match ($request->string('state')->toString()) {
            'open' => $q->where(fn ($w) => $w
                ->whereHas('phase', fn ($p) => $p->whereNull('outcome'))
                ->orWhereNull('opportunity_phase_id')),
            'won'  => $q->whereHas('phase', fn ($p) => $p->where('outcome', 'won')),
            'lost' => $q->whereHas('phase', fn ($p) => $p->where('outcome', 'lost')),
            default => null,
        };

        if ($term = trim((string) $request->query('search', ''))) {
            $q->where('name', 'ilike', '%' . $term . '%');
        }

        return OpportunityResource::collection($q->latest('id')->paginate((int) $request->input('per_page', 25)));
    }

    public function store(StoreOpportunityRequest $request): JsonResponse
    {
        $this->authorize('create', Opportunity::class);

        $opportunity = $this->service->createOpportunity(
            $request->validated(), app('current.organization_id'), $request->user()->id,
        );

        return (new OpportunityResource($opportunity->load(self::DETAIL)))->response()->setStatusCode(201);
    }

    public function show(Opportunity $opportunity): OpportunityResource
    {
        $this->authorize('view', $opportunity);

        return new OpportunityResource($opportunity->load(self::DETAIL));
    }

    public function update(StoreOpportunityRequest $request, Opportunity $opportunity): OpportunityResource
    {
        $this->authorize('update', $opportunity);

        $data = $request->validated();

        /*
         * Moving into Win or Lost through a plain edit would sidestep the
         * close permission. Dropped rather than refused, so saving a deal that
         * is already won does not fail on a field that has not changed.
         */
        if (array_key_exists('opportunity_phase_id', $data)
            && (int) $data['opportunity_phase_id'] !== (int) $opportunity->opportunity_phase_id) {
            $target = OpportunityPhase::find($data['opportunity_phase_id']);

            if ($target?->outcome !== null && ! $request->user()->can('close', $opportunity)) {
                unset($data['opportunity_phase_id']);
            }
        }

        $opportunity->update($data);

        return new OpportunityResource($opportunity->fresh(self::DETAIL));
    }

    public function destroy(Opportunity $opportunity): JsonResponse
    {
        $this->authorize('delete', $opportunity);
        $opportunity->delete();

        return response()->json(['message' => 'Opportunity removed.']);
    }

    /**
     * Move a deal along, or close it.
     *
     * Its own action because closing stamps when it closed and is a separate
     * authority — the won figure is what gets reported upwards.
     */
    public function moveToPhase(Request $request, Opportunity $opportunity): OpportunityResource
    {
        $data = $request->validate([
            'opportunity_phase_id' => ['required', 'integer',
                Rule::exists('opportunity_phases', 'id')->where('organization_id', $opportunity->organization_id)],
        ]);

        $phase = OpportunityPhase::findOrFail($data['opportunity_phase_id']);

        $this->authorize($phase->outcome ? 'close' : 'update', $opportunity);

        return new OpportunityResource(
            $this->service->moveToPhase($opportunity, $phase, $request->user()->id)->load(self::DETAIL)
        );
    }

    // ── Assignees ─────────────────────────────────────────────────────────

    public function storeAssignee(Request $request, Opportunity $opportunity): OpportunityResource
    {
        $this->authorize('update', $opportunity);

        $data = $request->validate([
            'employee_id' => ['required', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $opportunity->organization_id)],
            'role' => ['nullable', 'string', 'max:120'],
        ]);

        OpportunityAssignee::updateOrCreate(
            ['opportunity_id' => $opportunity->id, 'employee_id' => $data['employee_id']],
            ['organization_id' => $opportunity->organization_id, 'role' => $data['role'] ?? null],
        );

        return new OpportunityResource($opportunity->fresh(self::DETAIL));
    }

    public function destroyAssignee(Opportunity $opportunity, OpportunityAssignee $assignee): OpportunityResource
    {
        $this->authorize('update', $opportunity);
        abort_unless($assignee->opportunity_id === $opportunity->id, 404);

        $assignee->delete();

        return new OpportunityResource($opportunity->fresh(self::DETAIL));
    }

    /** The phases a deal can be in, for the forms that move one. */
    public function phases(): JsonResponse
    {
        $this->authorize('viewAny', Opportunity::class);

        return response()->json([
            'data' => OpportunityPhase::where('is_active', true)
                ->orderBy('position')->get(['id', 'name', 'position', 'outcome']),
        ]);
    }
}
