<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Support\Ticket;
use App\Models\Support\TicketRca;
use App\Services\Support\RcaPdf;
use App\Services\Support\RcaService;
use App\Support\Storage\DocumentVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class TicketRcaController extends Controller
{
    public function __construct(private readonly RcaService $service) {}

    public function show(int $ticket): JsonResponse
    {
        $record = Ticket::findOrFail($ticket);
        $this->authorize('view', $record);

        $rca = $this->service->find($record);
        $rca?->load(['evidenceFiles' => fn ($q) => $q->with('uploadedBy:id,name')->orderByDesc('created_at')]);

        return response()->json(['data' => $rca]);
    }

    public function update(Request $request, int $ticket): JsonResponse
    {
        $record = Ticket::findOrFail($ticket);
        $this->authorize('update', Ticket::class);

        $data = $request->validate([
            'title'                   => ['nullable', 'string', 'max:300'],
            'incident_date'           => ['nullable', 'date'],
            'prepared_date'           => ['nullable', 'date'],
            'system_affected'         => ['nullable', 'string', 'max:200'],
            'business_unit'           => ['nullable', 'string', 'max:200'],
            'severity'                => ['nullable', 'string', 'max:50'],
            'incident_owner'          => ['nullable', 'string', 'max:160'],
            'technical_owner'         => ['nullable', 'string', 'max:160'],
            'reviewers'               => ['nullable', 'array'],
            'reviewers.*'             => ['string', 'max:160'],

            'executive_summary'       => ['nullable', 'string', 'max:5000'],

            'incident_description'                        => ['nullable', 'array'],
            'incident_description.symptoms'                => ['nullable', 'string', 'max:3000'],
            'incident_description.systems_affected'         => ['nullable', 'string', 'max:3000'],
            'incident_description.users_affected'           => ['nullable', 'string', 'max:3000'],
            'incident_description.error_messages'           => ['nullable', 'string', 'max:3000'],
            'incident_description.detection_method'         => ['nullable', 'string', 'max:3000'],
            'incident_description.initial_observations'     => ['nullable', 'string', 'max:3000'],

            'business_impact'                              => ['nullable', 'array'],
            'business_impact.users_affected_count'          => ['nullable', 'string', 'max:100'],
            'business_impact.transactions_affected'         => ['nullable', 'string', 'max:100'],
            'business_impact.revenue_impact'                => ['nullable', 'string', 'max:100'],
            'business_impact.sla_impact'                    => ['nullable', 'string', 'max:2000'],
            'business_impact.operational_impact'            => ['nullable', 'string', 'max:2000'],
            'business_impact.customer_impact'               => ['nullable', 'string', 'max:2000'],
            'business_impact.compliance_security_impact'    => ['nullable', 'string', 'max:2000'],
            'business_impact.data_loss'                     => ['nullable', 'string', 'max:2000'],

            'timeline'          => ['nullable', 'array'],
            'timeline.*.time'   => ['nullable', 'string', 'max:60'],
            'timeline.*.event'  => ['nullable', 'string', 'max:500'],

            'technical_investigation' => ['nullable', 'string', 'max:5000'],

            'immediate_cause'      => ['nullable', 'string', 'max:3000'],
            'root_cause'            => ['nullable', 'string', 'max:3000'],
            'contributing_factors'  => ['nullable', 'string', 'max:3000'],
            'control_failure'       => ['nullable', 'string', 'max:3000'],

            'five_whys'            => ['nullable', 'array', 'max:10'],
            'five_whys.*.question' => ['nullable', 'string', 'max:500'],
            'five_whys.*.answer'   => ['nullable', 'string', 'max:1000'],
            'final_root_cause'     => ['nullable', 'string', 'max:2000'],

            'root_cause_category' => ['nullable', 'string', Rule::in(TicketRca::CATEGORIES)],

            'resolution' => ['nullable', 'string', 'max:5000'],

            'corrective_actions'            => ['nullable', 'array'],
            'corrective_actions.*.action'   => ['nullable', 'string', 'max:500'],
            'corrective_actions.*.owner'    => ['nullable', 'string', 'max:160'],
            'corrective_actions.*.priority' => ['nullable', 'string', 'max:20'],
            'corrective_actions.*.due_date' => ['nullable', 'string', 'max:40'],
            'corrective_actions.*.status'   => ['nullable', 'string', 'max:20'],

            'preventive_actions'            => ['nullable', 'array'],
            'preventive_actions.*.action'   => ['nullable', 'string', 'max:500'],
            'preventive_actions.*.owner'    => ['nullable', 'string', 'max:160'],
            'preventive_actions.*.priority' => ['nullable', 'string', 'max:20'],
            'preventive_actions.*.due_date' => ['nullable', 'string', 'max:40'],
            'preventive_actions.*.status'   => ['nullable', 'string', 'max:20'],

            'lessons_learned' => ['nullable', 'string', 'max:5000'],
            'evidence'        => ['nullable', 'string', 'max:5000'],

            'risk_assessment'                          => ['nullable', 'array'],
            'risk_assessment.similar_systems'           => ['nullable', 'string', 'max:2000'],
            'risk_assessment.probability'               => ['nullable', 'string', 'max:100'],
            'risk_assessment.impact'                    => ['nullable', 'string', 'max:2000'],
            'risk_assessment.existing_controls'         => ['nullable', 'string', 'max:2000'],
            'risk_assessment.control_gaps'              => ['nullable', 'string', 'max:2000'],
            'risk_assessment.recommended_treatment'     => ['nullable', 'string', 'max:2000'],

            'status' => ['nullable', 'string', Rule::in([TicketRca::STATUS_DRAFT, TicketRca::STATUS_FINAL])],
        ]);

        $rca = $this->service->save($record, $data, $request->user());

        return response()->json(['data' => $rca]);
    }

    public function pdf(int $ticket, RcaPdf $pdf): Response|JsonResponse
    {
        $record = Ticket::findOrFail($ticket);
        $this->authorize('view', $record);

        $rca = $this->service->find($record);
        if (! $rca) {
            return response()->json(['message' => 'No RCA has been started for this ticket yet.'], 404);
        }
        $rca->load('evidenceFiles');

        $document = $pdf->render($rca, $record);

        return response($document['content'], 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $document['filename'] . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ── Evidence files ───────────────────────────────────────────────────────

    public function uploadEvidence(Request $request, int $ticket): JsonResponse
    {
        $record = Ticket::findOrFail($ticket);
        $this->authorize('update', Ticket::class);

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx'],
        ]);

        $rca  = $this->service->getOrCreate($record, $request->user());
        $file = $this->service->uploadEvidence($rca, $request->file('file'), $request->user()->id);

        return response()->json(['data' => $file], 201);
    }

    public function downloadEvidence(int $ticket, int $evidenceFile): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $record = Ticket::findOrFail($ticket);
        $this->authorize('view', $record);

        $rca  = $record->rca()->firstOrFail();
        $file = $rca->evidenceFiles()->findOrFail($evidenceFile);

        abort_unless($file->fileExists(), 404, 'File not found.');

        return DocumentVault::download(
            'rca_evidence', $file->storage_path, $file->original_name,
            $file->mime_type ?? 'application/octet-stream',
        );
    }

    public function destroyEvidence(int $ticket, int $evidenceFile): JsonResponse
    {
        $record = Ticket::findOrFail($ticket);
        $this->authorize('update', Ticket::class);

        $rca  = $record->rca()->firstOrFail();
        $file = $rca->evidenceFiles()->findOrFail($evidenceFile);
        $file->delete();

        return response()->json(['message' => 'Evidence file removed.']);
    }
}
