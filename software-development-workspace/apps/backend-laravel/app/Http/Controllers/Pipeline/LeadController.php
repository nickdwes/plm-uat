<?php

namespace App\Http\Controllers\Pipeline;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pipeline\StoreLeadRequest;
use App\Http\Requests\Pipeline\StoreOpportunityRequest;
use App\Http\Resources\Pipeline\LeadResource;
use App\Http\Resources\Pipeline\OpportunityResource;
use App\Models\Pipeline\Lead;
use App\Models\Pipeline\LeadTeamMember;
use App\Services\Pipeline\PipelineService;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    private const DETAIL = ['account', 'owner', 'team.employee', 'opportunity'];

    public function __construct(private readonly PipelineService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Lead::class);

        $q = Lead::query()->with(['account', 'owner']);

        // Someone holding only `view_own` sees the leads they own or research.
        // Resolved from the authenticated user rather than a request parameter,
        // which the caller controls.
        if (! $request->user()->hasPermission(Permission::PIPELINE_VIEW)) {
            $employeeId = $request->user()->employeeId();
            $q->where(fn ($w) => $w->where('owner_id', $employeeId)
                ->orWhereHas('team', fn ($t) => $t->where('employee_id', $employeeId)));
        }

        if ($accountId = $request->integer('account_id')) {
            $q->where('account_id', $accountId);
        }

        if ($status = $request->string('status')->toString()) {
            // `open` is the question the legacy status could not answer,
            // because two of its values secretly meant "converted".
            $status === 'open' ? $q->open() : $q->where('status', $status);
        }

        if ($term = trim((string) $request->query('search', ''))) {
            $q->where('name', 'ilike', '%' . $term . '%');
        }

        return LeadResource::collection($q->latest('id')->paginate((int) $request->input('per_page', 25)));
    }

    public function store(StoreLeadRequest $request): JsonResponse
    {
        $this->authorize('create', Lead::class);

        $lead = $this->service->createLead(
            $request->validated(), app('current.organization_id'), $request->user()->id,
        );

        return (new LeadResource($lead->load(self::DETAIL)))->response()->setStatusCode(201);
    }

    public function show(Lead $lead): LeadResource
    {
        $this->authorize('view', $lead);

        return new LeadResource($lead->load(self::DETAIL));
    }

    public function update(StoreLeadRequest $request, Lead $lead): LeadResource
    {
        $this->authorize('update', $lead);

        $lead->update($request->validated());

        return new LeadResource($lead->fresh(self::DETAIL));
    }

    public function destroy(Lead $lead): JsonResponse
    {
        $this->authorize('delete', $lead);
        $lead->delete();

        return response()->json(['message' => 'Lead removed.']);
    }

    /**
     * Turn a qualified lead into a deal.
     *
     * Gated on creating an opportunity rather than editing the lead: it brings
     * a deal into existence, and whoever may edit a lead is not necessarily
     * who may put revenue on the board.
     */
    public function convert(StoreOpportunityRequest $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);
        $this->authorize('create', \App\Models\Pipeline\Opportunity::class);

        $opportunity = $this->service->convertLead($lead, $request->validated(), $request->user()->id);

        return (new OpportunityResource(
            $opportunity->load(['account', 'phase', 'owner', 'presaleLead', 'assignees.employee', 'lead'])
        ))->response()->setStatusCode(201);
    }

    // ── Research team ─────────────────────────────────────────────────────

    public function storeTeamMember(Request $request, Lead $lead): LeadResource
    {
        $this->authorize('update', $lead);

        $data = $request->validate([
            'employee_id' => ['required', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $lead->organization_id)],
            'role' => ['nullable', 'string', 'max:120'],
        ]);

        LeadTeamMember::updateOrCreate(
            ['lead_id' => $lead->id, 'employee_id' => $data['employee_id']],
            ['organization_id' => $lead->organization_id, 'role' => $data['role'] ?? null],
        );

        return new LeadResource($lead->fresh(self::DETAIL));
    }

    public function destroyTeamMember(Lead $lead, LeadTeamMember $member): LeadResource
    {
        $this->authorize('update', $lead);
        abort_unless($member->lead_id === $lead->id, 404);

        $member->delete();

        return new LeadResource($lead->fresh(self::DETAIL));
    }
}
