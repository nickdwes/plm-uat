<?php

namespace App\Http\Controllers\Pipeline;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pipeline\StoreLeadRequest;
use App\Http\Requests\Pipeline\StoreOpportunityRequest;
use App\Http\Resources\Pipeline\LeadResource;
use App\Http\Resources\Pipeline\OpportunityResource;
use App\Models\Pipeline\Lead;
use App\Http\Resources\Pipeline\LeadActivityResource;
use App\Models\Pipeline\LeadActivity;
use App\Models\Pipeline\LeadAttachment;
use App\Models\Pipeline\LeadStakeholder;
use App\Models\Pipeline\LeadTeamMember;
use App\Services\Pipeline\PipelineService;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Support\Storage\DocumentVault;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadController extends Controller
{
    private const DETAIL = [
        'account', 'owner', 'team.employee', 'opportunity', 'stakeholders', 'attachments',
    ];

    public function __construct(private readonly PipelineService $service) {}

    /**
     * Narrow to what this person may see.
     *
     * Someone holding only `view_own` sees the leads they own or research.
     * Resolved from the authenticated user rather than a request parameter,
     * which the caller controls.
     */
    private function limitToOwn($q, Request $request): void
    {
        if ($request->user()->hasPermission(Permission::PIPELINE_VIEW)) {
            return;
        }

        $employeeId = $request->user()->employeeId();

        $q->where(fn ($w) => $w->where('owner_id', $employeeId)
            ->orWhereHas('team', fn ($t) => $t->where('employee_id', $employeeId)));
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Lead::class);

        $q = Lead::query()->with(['account', 'owner']);

        $this->limitToOwn($q, $request);

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

    /**
     * The board.
     *
     * Grouped here rather than in the client, so a column's count is the
     * number of leads in it and not the number the page happened to fetch —
     * a board that says 10 while showing 3 is worse than no count.
     */
    public function board(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $q = Lead::query()->with(['owner', 'account', 'team.employee'])
            ->whereIn('status', Lead::BOARD_STATUSES);

        $this->limitToOwn($q, $request);

        if ($term = trim((string) $request->query('search', ''))) {
            $q->where(fn ($w) => $w->where('name', 'ilike', '%' . $term . '%')
                ->orWhere('summary', 'ilike', '%' . $term . '%'));
        }

        if ($priority = $request->string('priority')->toString()) {
            $q->where('priority', $priority);
        }

        $all = $q->latest('updated_at')->get()->groupBy('status');

        return response()->json([
            'data' => collect(Lead::BOARD_STATUSES)->map(fn (string $status) => [
                'status' => $status,
                'total'  => $all->get($status)?->count() ?? 0,
                // Capped so a column of 400 does not stall the board. The
                // total above is the real count, so the cap is visible rather
                // than silently truncating.
                'leads'  => LeadResource::collection(($all->get($status) ?? collect())->take(50)),
            ]),
        ]);
    }

    /**
     * Move a lead between columns.
     *
     * Its own action rather than an edit, because dragging a card is one
     * gesture and should not require sending back every field on the lead —
     * which is how a drag ends up blanking a description.
     */
    public function moveToStatus(Request $request, Lead $lead): LeadResource
    {
        $this->authorize('update', $lead);

        $data = $request->validate([
            'status' => ['required', Rule::in(Lead::BOARD_STATUSES)],
        ]);

        abort_if($lead->status === 'converted', 422, 'A converted lead has left the board.');

        $lead->update($data);

        return new LeadResource($lead->fresh(self::DETAIL));
    }

    // ── Qualification ─────────────────────────────────────────────────────

    /**
     * Grade the five dimensions.
     *
     * Its own endpoint because assessing a lead is a different act from
     * editing one, and the summary judgements beside it — budget available,
     * stakeholders engaged — are the reasoning behind the grades rather than
     * facts about the lead itself.
     */
    public function qualify(Request $request, Lead $lead): LeadResource
    {
        $this->authorize('update', $lead);

        $grade = ['nullable', 'integer', 'min:0', 'max:100'];

        $data = $request->validate([
            'fit_company'     => $grade,
            'fit_budget'      => $grade,
            'fit_solution'    => $grade,
            'fit_timeline'    => $grade,
            'fit_stakeholder' => $grade,

            'budget_availability'       => ['nullable', Rule::in(['available', 'limited', 'none'])],
            'stakeholder_engagement'    => ['nullable', Rule::in(['high', 'medium', 'low'])],
            'timeline_estimate'         => ['nullable', 'string', 'max:64'],
            'technical_readiness'       => ['nullable', Rule::in(['high', 'medium', 'low'])],
            'decision_authority_access' => ['nullable', Rule::in(['available', 'limited', 'none'])],
            'qualification_notes'       => ['nullable', 'string', 'max:20000'],
        ]);

        $lead->update($data);

        return new LeadResource($lead->fresh(self::DETAIL));
    }

    // ── Stakeholders ──────────────────────────────────────────────────────

    public function storeStakeholder(Request $request, Lead $lead): LeadResource
    {
        $this->authorize('update', $lead);

        $data = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'role'      => ['nullable', 'string', 'max:255'],
            'email'     => ['nullable', 'email', 'max:255'],
            'phone'     => ['nullable', 'string', 'max:32'],
            'influence' => ['nullable', Rule::in(LeadStakeholder::INFLUENCES)],

            // Scoped to this lead's customer, so a decision maker cannot be
            // borrowed from somebody else's contact list.
            'account_contact_id' => ['nullable', 'integer',
                Rule::exists('account_contacts', 'id')->where('account_id', $lead->account_id)],
        ]);

        $lead->stakeholders()->create($data + ['organization_id' => $lead->organization_id]);

        return new LeadResource($lead->fresh(self::DETAIL));
    }

    public function destroyStakeholder(Lead $lead, LeadStakeholder $stakeholder): LeadResource
    {
        $this->authorize('update', $lead);
        abort_unless($stakeholder->lead_id === $lead->id, 404);

        $stakeholder->delete();

        return new LeadResource($lead->fresh(self::DETAIL));
    }

    // ── Activities ────────────────────────────────────────────────────────

    public function activities(Lead $lead): AnonymousResourceCollection
    {
        $this->authorize('view', $lead);

        return LeadActivityResource::collection(
            $lead->activities()->with(['employee', 'author'])->limit(200)->get()
        );
    }

    public function storeActivity(Request $request, Lead $lead): JsonResponse
    {
        // Logging what happened is not editing the lead: anybody working it
        // should be able to say that a meeting took place.
        $this->authorize('view', $lead);

        $data = $request->validate([
            'type'        => ['sometimes', Rule::in(LeadActivity::TYPES)],
            'body'        => ['required', 'string', 'max:5000'],
            'occurred_at' => ['sometimes', 'date', 'before_or_equal:now'],
        ]);

        $activity = $lead->activities()->create([
            'organization_id' => $lead->organization_id,
            'type'            => $data['type'] ?? 'note',
            'body'            => $data['body'],
            'occurred_at'     => $data['occurred_at'] ?? now(),
            'employee_id'     => $request->user()->employeeId(),
            'created_by'      => $request->user()->id,
        ]);

        return (new LeadActivityResource($activity->load(['employee', 'author'])))
            ->response()->setStatusCode(201);
    }

    // ── Documents ─────────────────────────────────────────────────────────

    public function storeAttachment(Request $request, Lead $lead): LeadResource
    {
        $this->authorize('update', $lead);

        $data = $request->validate([
            'file'          => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg'],
            'label'         => ['nullable', 'string', 'max:255'],
            'document_type' => ['sometimes', Rule::in(LeadAttachment::DOCUMENT_TYPES)],
        ]);

        $file = $request->file('file');

        $lead->attachments()->create([
            'organization_id' => $lead->organization_id,
            'document_type'   => $data['document_type'] ?? 'internal',
            // Falls back to the filename: an untitled row nobody can
            // identify is worse than a clumsy name.
            'label'           => ($data['label'] ?? null) ?: $file->getClientOriginalName(),
            'original_name'   => $file->getClientOriginalName(),
            'storage_path'    => DocumentVault::store($file, 'lead_documents', "leads/{$lead->id}"),
            'mime_type'       => $file->getClientMimeType(),
            'size_bytes'      => $file->getSize(),
            'uploaded_by'     => $request->user()->id,
        ]);

        return new LeadResource($lead->fresh(self::DETAIL));
    }

    public function downloadAttachment(Lead $lead, LeadAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $lead);
        abort_unless($attachment->lead_id === $lead->id, 404);

        return DocumentVault::download(
            'lead_documents', $attachment->storage_path,
            $attachment->original_name, $attachment->mime_type,
        );
    }

    public function destroyAttachment(Lead $lead, LeadAttachment $attachment): LeadResource
    {
        $this->authorize('update', $lead);
        abort_unless($attachment->lead_id === $lead->id, 404);

        // Soft-deleted, and the file is left alone: taking a requirements
        // document off the screen should not destroy what we quoted against.
        $attachment->delete();

        return new LeadResource($lead->fresh(self::DETAIL));
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
