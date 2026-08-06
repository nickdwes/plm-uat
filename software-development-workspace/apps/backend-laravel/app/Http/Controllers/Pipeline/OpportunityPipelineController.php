<?php

namespace App\Http\Controllers\Pipeline;

use App\Http\Controllers\Controller;
use App\Models\Pipeline\Opportunity;
use App\Models\Pipeline\OpportunityBid;
use App\Models\Pipeline\OpportunityDocument;
use App\Models\Pipeline\OpportunityEstimate;
use App\Models\Pipeline\OpportunityObjective;
use App\Models\Pipeline\OpportunityPhase;
use App\Models\Project\Project;
use App\Services\Pipeline\OpportunityStageService;
use App\Support\Account\ReferenceSequence;
use App\Support\Pipeline\LeadQualification;
use App\Support\Pipeline\OpportunityScore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The stages of an opportunity.
 *
 * Kept apart from OpportunityController, which owns the deal itself. This one
 * owns what happens to it: estimates, bids, budget confirmation, commitments,
 * contracting and kickoff. They are one screen but eleven different acts, and
 * putting them beside create-read-update-delete would bury both.
 *
 * Every file goes through storeDocument. There is exactly one upload path, one
 * private disk and one authorised download, which is the point — the legacy
 * had twenty file columns and Plmv2 seventeen path strings, and each one was
 * a separate chance to get storage wrong.
 */
class OpportunityPipelineController extends Controller
{
    public function __construct(private readonly OpportunityStageService $stages) {}

    // ── The board ─────────────────────────────────────────────────────────

    /**
     * Everything the pipeline screen needs, in one response.
     *
     * The stage rail, what is complete, and each stage's contents. Fetching
     * eleven endpoints as somebody clicks down the rail would make the screen
     * feel broken on a slow connection, and the payload is small — the
     * documents are rows, not files.
     */
    public function show(Opportunity $opportunity): JsonResponse
    {
        $this->authorize('view', $opportunity);

        $opportunity->load([
            'phase', 'stageProgress', 'estimates.documents', 'objectives',
            'bids.documents', 'documents', 'contracts.attachments',
            'projects', 'researches.researchLead',
        ]);

        $phases = OpportunityPhase::where('organization_id', $opportunity->organization_id)
            ->where('is_active', true)->whereNull('outcome')
            ->orderBy('position')->get();

        $done = $opportunity->stageProgress->pluck('completed_at', 'opportunity_phase_id');

        return response()->json([
            'data' => [
                'stages' => $phases->map(fn ($p) => [
                    'id'           => $p->id,
                    'name'         => $p->name,
                    'position'     => $p->position,
                    'is_current'   => $opportunity->opportunity_phase_id === $p->id,
                    'completed_at' => $done->get($p->id)?->toIso8601String(),
                ]),

                // ── Processing ────────────────────────────────────────────
                'strength' => [
                    'score'       => $opportunity->score,
                    'max'         => OpportunityScore::MAX,
                    'band'        => $opportunity->score_band,
                    'threshold'   => OpportunityScore::COMMIT_THRESHOLD,
                    'meets'       => OpportunityScore::meetsCommitThreshold($opportunity),
                    'indicators'  => collect(OpportunityScore::INDICATORS)
                        ->map(fn ($meta, $key) => [
                            'key'   => $key,
                            'label' => $meta['label'],
                            'help'  => $meta['help'],
                            'met'   => (bool) $opportunity->{$key},
                        ])->values(),
                ],

                // ── Qualification ────────────────────────────────────────
                'qualification' => [
                    'dimensions' => collect(LeadQualification::DIMENSIONS)
                        ->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'value' => $opportunity->{$key}])
                        ->values(),
                    'score'        => $opportunity->fit_score,
                    'band'         => LeadQualification::band($opportunity->fit_score),
                    'completeness' => LeadQualification::completeness($opportunity),
                    'percentile'   => LeadQualification::percentile($opportunity, 'opportunities'),
                    'summary'      => [
                        'budget_availability'       => $opportunity->budget_availability,
                        'stakeholder_engagement'    => $opportunity->stakeholder_engagement,
                        'timeline_estimate'         => $opportunity->timeline_estimate,
                        'technical_readiness'       => $opportunity->technical_readiness,
                        'decision_authority_access' => $opportunity->decision_authority_access,
                    ],
                    'qualified_at' => $opportunity->qualified_at?->toIso8601String(),
                ],

                // ── Budget confirmation ──────────────────────────────────
                'budget' => [
                    'amount'       => $opportunity->confirmed_budget,
                    'is_confirmed' => $opportunity->budget_is_confirmed,
                    'confirmed_at' => $opportunity->budget_confirmed_at?->toIso8601String(),
                ],

                'estimates'  => $opportunity->estimates->map(fn ($e) => [
                    'id' => $e->id, 'name' => $e->name,
                    'budget' => $e->budget, 'revenue' => $e->revenue,
                    'cost' => $e->cost, 'profit' => $e->profit,
                    // Surfaced so a figure that does not add up is visible
                    // rather than quietly corrected.
                    'implied_profit' => $e->implied_profit,
                    'documents' => $this->documentPayload($e->documents),
                ]),

                'objectives' => $opportunity->objectives->map(fn ($o) => [
                    'id' => $o->id, 'name' => $o->name,
                    'expected_start_date' => $o->expected_start_date?->toDateString(),
                    'expected_end_date'   => $o->expected_end_date?->toDateString(),
                    'quarter_span'        => $o->quarter_span,
                ]),

                'bids' => $opportunity->bids->map(fn ($b) => [
                    'id' => $b->id, 'tender_number' => $b->tender_number, 'bid_number' => $b->bid_number,
                    'tender_name' => $b->tender_name, 'status' => $b->status,
                    'tender_validity_date'   => $b->tender_validity_date?->toDateString(),
                    'bid_bond_validity_date' => $b->bid_bond_validity_date?->toDateString(),
                    'bid_bond_value' => $b->bid_bond_value,
                    'bidding_cost'   => $b->bidding_cost,
                    'bidding_value'  => $b->bidding_value,
                    'bidding_profit' => $b->bidding_profit,
                    'tender_close_date'   => $b->tender_close_date?->toDateString(),
                    'expected_award_date' => $b->expected_award_date?->toDateString(),
                    // A bond that lapses before the award is a bid that cannot
                    // be honoured, which nothing else would surface.
                    'bond_lapses_before_award' => $b->bond_lapses_before_award,
                    'outcome_notes' => $b->outcome_notes,
                    'documents'     => $this->documentPayload($b->documents),
                ]),

                'contracts' => $opportunity->contracts->map(fn ($c) => [
                    'id' => $c->id, 'reference' => $c->reference, 'name' => $c->name,
                    'contract_value' => $c->contract_value, 'billing_cycle' => $c->billing_cycle,
                    'owner' => $c->owner, 'status' => $c->status,
                    'start_date' => $c->start_date?->toDateString(),
                    'end_date'   => $c->end_date?->toDateString(),
                ]),

                'projects' => $opportunity->projects->map(fn ($p) => [
                    'id' => $p->id, 'reference' => $p->reference, 'name' => $p->name,
                    'priority' => $p->priority, 'status' => $p->status,
                    'deadline' => $p->deadline?->toDateString(),
                ]),

                'researches' => $opportunity->researches->map(fn ($r) => [
                    'id' => $r->id, 'reference' => $r->reference, 'name' => $r->name,
                    'status' => $r->status, 'priority' => $r->priority,
                    'deadline' => $r->deadline?->toDateString(),
                    'lead' => $r->researchLead?->full_name,
                ]),

                // Grouped by stage so each panel can render its own without
                // filtering the whole set client-side.
                'documents' => $opportunity->documents->groupBy('stage')
                    ->map(fn ($docs) => $this->documentPayload($docs)),
            ],
        ]);
    }

    // ── Stage progress ────────────────────────────────────────────────────

    public function completeStage(Request $request, Opportunity $opportunity, OpportunityPhase $phase): JsonResponse
    {
        $this->authorize('update', $opportunity);
        abort_unless($phase->organization_id === $opportunity->organization_id, 404);

        $this->stages->complete($opportunity, $phase, $request->user()->id);

        return $this->show($opportunity->fresh());
    }

    public function reopenStage(Opportunity $opportunity, OpportunityPhase $phase): JsonResponse
    {
        $this->authorize('update', $opportunity);
        abort_unless($phase->organization_id === $opportunity->organization_id, 404);

        $this->stages->reopen($opportunity, $phase);

        return $this->show($opportunity->fresh());
    }

    // ── Processing: the ten indicators ────────────────────────────────────

    public function updateIndicators(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('update', $opportunity);

        $rules = [];
        foreach (array_keys(OpportunityScore::INDICATORS) as $key) {
            $rules[$key] = ['sometimes', 'boolean'];
        }

        $opportunity->update($request->validate($rules));

        return $this->show($opportunity->fresh());
    }

    // ── Qualification ─────────────────────────────────────────────────────

    public function qualify(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('update', $opportunity);

        $grade = ['nullable', 'integer', 'min:0', 'max:100'];

        $opportunity->update($request->validate([
            'fit_company' => $grade, 'fit_budget' => $grade, 'fit_solution' => $grade,
            'fit_timeline' => $grade, 'fit_stakeholder' => $grade,
            'budget_availability'       => ['nullable', Rule::in(['available', 'limited', 'none'])],
            'stakeholder_engagement'    => ['nullable', Rule::in(['high', 'medium', 'low'])],
            'timeline_estimate'         => ['nullable', 'string', 'max:64'],
            'technical_readiness'       => ['nullable', Rule::in(['high', 'medium', 'low'])],
            'decision_authority_access' => ['nullable', Rule::in(['available', 'limited', 'none'])],
        ]));

        return $this->show($opportunity->fresh());
    }

    /** Mark the deal qualified — refused if nothing has been assessed. */
    public function markQualified(Opportunity $opportunity): JsonResponse
    {
        $this->authorize('update', $opportunity);

        $this->stages->qualify($opportunity);

        return $this->show($opportunity->fresh());
    }

    // ── Estimates ─────────────────────────────────────────────────────────

    public function storeEstimate(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('update', $opportunity);

        $money = ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'];

        $data = $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'budget'  => $money, 'revenue' => $money, 'cost' => $money, 'profit' => $money,
        ]);

        $opportunity->estimates()->create($data + [
            'organization_id' => $opportunity->organization_id,
            'created_by'      => $request->user()->id,
        ]);

        return $this->show($opportunity->fresh());
    }

    public function destroyEstimate(Opportunity $opportunity, OpportunityEstimate $estimate): JsonResponse
    {
        $this->authorize('update', $opportunity);
        abort_unless($estimate->opportunity_id === $opportunity->id, 404);

        $estimate->delete();

        return $this->show($opportunity->fresh());
    }

    // ── Budget confirmation ───────────────────────────────────────────────

    public function confirmBudget(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('update', $opportunity);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
        ]);

        $this->stages->confirmBudget($opportunity, (float) $data['amount'], $request->user()->id);

        return $this->show($opportunity->fresh());
    }

    public function revokeBudget(Opportunity $opportunity): JsonResponse
    {
        $this->authorize('update', $opportunity);

        $this->stages->revokeBudget($opportunity);

        return $this->show($opportunity->fresh());
    }

    // ── Commit ────────────────────────────────────────────────────────────

    public function storeObjective(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('update', $opportunity);

        $data = $request->validate([
            'name'                => ['required', 'string', 'max:255'],
            'expected_start_date' => ['required', 'date'],
            // An objective that ends before it starts is a typo every time,
            // and it makes the quarter span nonsense.
            'expected_end_date'   => ['required', 'date', 'after_or_equal:expected_start_date'],
        ]);

        $opportunity->objectives()->create($data + [
            'organization_id' => $opportunity->organization_id,
            'created_by'      => $request->user()->id,
        ]);

        return $this->show($opportunity->fresh());
    }

    public function updateObjective(Request $request, Opportunity $opportunity, OpportunityObjective $objective): JsonResponse
    {
        $this->authorize('update', $opportunity);
        abort_unless($objective->opportunity_id === $opportunity->id, 404);

        $objective->update($request->validate([
            'name'                => ['required', 'string', 'max:255'],
            'expected_start_date' => ['required', 'date'],
            'expected_end_date'   => ['required', 'date', 'after_or_equal:expected_start_date'],
        ]));

        return $this->show($opportunity->fresh());
    }

    public function destroyObjective(Opportunity $opportunity, OpportunityObjective $objective): JsonResponse
    {
        $this->authorize('update', $opportunity);
        abort_unless($objective->opportunity_id === $opportunity->id, 404);

        $objective->delete();

        return $this->show($opportunity->fresh());
    }

    // ── Bidding ───────────────────────────────────────────────────────────

    public function storeBid(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('update', $opportunity);

        $opportunity->bids()->create($this->bidRules($request) + [
            'organization_id' => $opportunity->organization_id,
            'created_by'      => $request->user()->id,
        ]);

        return $this->show($opportunity->fresh());
    }

    public function updateBid(Request $request, Opportunity $opportunity, OpportunityBid $bid): JsonResponse
    {
        $this->authorize('update', $opportunity);
        abort_unless($bid->opportunity_id === $opportunity->id, 404);

        $bid->update($this->bidRules($request));

        return $this->show($opportunity->fresh());
    }

    public function destroyBid(Opportunity $opportunity, OpportunityBid $bid): JsonResponse
    {
        $this->authorize('update', $opportunity);
        abort_unless($bid->opportunity_id === $opportunity->id, 404);

        // Soft-deleted: a withdrawn bid is still a bid that was submitted, and
        // the bond that went with it was still lodged.
        $bid->delete();

        return $this->show($opportunity->fresh());
    }

    private function bidRules(Request $request): array
    {
        $money = ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'];

        return $request->validate([
            'tender_number' => ['required', 'string', 'max:120'],
            'bid_number'    => ['nullable', 'string', 'max:120'],
            'tender_name'   => ['nullable', 'string', 'max:255'],

            'tender_validity_date'   => ['nullable', 'date'],
            'bid_bond_validity_date' => ['nullable', 'date'],
            'bid_bond_value'         => $money,
            'bidding_cost'           => $money,
            'bidding_value'          => $money,
            'tender_close_date'      => ['nullable', 'date'],
            'expected_award_date'    => ['nullable', 'date'],

            'status'        => ['sometimes', Rule::in(OpportunityBid::STATUSES)],
            'scope_agreed'  => ['sometimes', 'boolean'],
            'outcome_notes' => ['nullable', 'string', 'max:20000'],
        ]);
    }

    // ── Contracting ───────────────────────────────────────────────────────

    /**
     * The contract a won deal produced.
     *
     * Written into the customer's contracts rather than a table of its own, so
     * the customer record and the deal show the same agreement. The legacy and
     * Plmv2 both kept a second copy on the opportunity, and the two drifted.
     */
    public function storeContract(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('update', $opportunity);

        $data = $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'start_date'     => ['required', 'date'],
            'end_date'       => ['required', 'date', 'after_or_equal:start_date'],
            'billing_cycle'  => ['nullable', 'string', 'max:32'],
            'contract_value' => ['nullable', 'numeric', 'min:0', 'max:99999999999.99'],
            'owner'          => ['nullable', 'string', 'max:255'],
        ]);

        $opportunity->contracts()->create($data + [
            'reference'       => ReferenceSequence::next('CT', 'account_contracts', $opportunity->organization_id),
            'organization_id' => $opportunity->organization_id,
            'account_id'      => $opportunity->account_id,
            'status'          => 'in_progress',
            'created_by'      => $request->user()->id,
        ]);

        return $this->show($opportunity->fresh());
    }

    // ── Project kickoff ───────────────────────────────────────────────────

    public function storeProject(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('update', $opportunity);

        $data = $request->validate([
            'name'            => ['required', 'string', 'max:255'],
            'deadline'        => ['nullable', 'date'],
            'priority'        => ['sometimes', Rule::in(Project::PRIORITIES)],
            'description'     => ['nullable', 'string', 'max:20000'],
            'project_lead_id' => ['nullable', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $opportunity->organization_id)],
        ]);

        $opportunity->projects()->create($data + [
            'reference'       => ReferenceSequence::next('PR', 'projects', $opportunity->organization_id),
            'organization_id' => $opportunity->organization_id,
            'account_id'      => $opportunity->account_id,
            'created_by'      => $request->user()->id,
        ]);

        return $this->show($opportunity->fresh());
    }

    // ── Documents ─────────────────────────────────────────────────────────

    /**
     * The single upload path.
     *
     * Every stage's files come through here, onto one private disk, and are
     * readable only through downloadDocument below. That is the whole point of
     * the design: the legacy had twenty file columns and Plmv2 seventeen path
     * strings, and each of them was a separate opportunity to get it wrong.
     */
    public function storeDocument(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('update', $opportunity);

        $data = $request->validate([
            'file'  => ['required', 'file', 'max:20480',
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg,zip'],
            'stage' => ['required', Rule::in(OpportunityDocument::STAGES)],
            'slot'  => ['nullable', Rule::in(OpportunityDocument::SLOTS)],
            'label' => ['nullable', 'string', 'max:255'],
            'document_type' => ['sometimes', Rule::in(OpportunityDocument::DOCUMENT_TYPES)],

            // Scoped to this opportunity's own bids and estimates, so a file
            // cannot be filed against somebody else's tender.
            'bid_id' => ['nullable', 'integer',
                Rule::exists('opportunity_bids', 'id')->where('opportunity_id', $opportunity->id)],
            'estimate_id' => ['nullable', 'integer',
                Rule::exists('opportunity_estimates', 'id')->where('opportunity_id', $opportunity->id)],
        ]);

        $file = $request->file('file');

        $opportunity->documents()->create([
            'organization_id' => $opportunity->organization_id,
            'stage'           => $data['stage'],
            'slot'            => $data['slot'] ?? null,
            'document_type'   => $data['document_type'] ?? 'internal',
            'label'           => ($data['label'] ?? null) ?: $file->getClientOriginalName(),
            'original_name'   => $file->getClientOriginalName(),
            'storage_path'    => $file->store("opportunities/{$opportunity->id}", 'opportunity_documents'),
            'mime_type'       => $file->getClientMimeType(),
            'size_bytes'      => $file->getSize(),
            'bid_id'          => $data['bid_id'] ?? null,
            'estimate_id'     => $data['estimate_id'] ?? null,
            'uploaded_by'     => $request->user()->id,
        ]);

        return $this->show($opportunity->fresh());
    }

    public function downloadDocument(Opportunity $opportunity, OpportunityDocument $document): StreamedResponse
    {
        $this->authorize('view', $opportunity);
        abort_unless($document->opportunity_id === $opportunity->id, 404);

        return Storage::disk('opportunity_documents')
            ->download($document->storage_path, $document->original_name);
    }

    public function destroyDocument(Opportunity $opportunity, OpportunityDocument $document): JsonResponse
    {
        $this->authorize('update', $opportunity);
        abort_unless($document->opportunity_id === $opportunity->id, 404);

        // Soft-deleted, and the file is left alone. A tender response removed
        // from the screen is still what we submitted.
        $document->delete();

        return $this->show($opportunity->fresh());
    }

    /** @param iterable<OpportunityDocument> $documents */
    private function documentPayload(iterable $documents): array
    {
        return collect($documents)->map(fn (OpportunityDocument $d) => [
            'id'            => $d->id,
            'stage'         => $d->stage,
            'slot'          => $d->slot,
            'document_type' => $d->document_type,
            'label'         => $d->label,
            'original_name' => $d->original_name,
            'size_bytes'    => $d->size_bytes,
            'uploaded_at'   => $d->created_at?->toIso8601String(),
            // No URL. Served through downloadDocument, which authorises.
        ])->values()->all();
    }
}
