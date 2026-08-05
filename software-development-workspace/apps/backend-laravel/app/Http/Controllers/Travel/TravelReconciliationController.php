<?php

namespace App\Http\Controllers\Travel;

use App\Http\Controllers\Controller;
use App\Http\Resources\Travel\TravelRequestResource;
use App\Models\Travel\TravelExpenseEvidence;
use App\Models\Travel\TravelRequest;
use App\Models\Travel\TravelRequestItem;
use App\Services\Travel\TravelReconciliationService;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Closing a trip: receipts, acknowledgements and the final close.
 *
 * Who may supply evidence is decided by the category, not by a blanket
 * permission. A traveller produces the Uber receipt because they took the
 * ride; finance produces the airline invoice because the traveller never sees
 * one. Asking the wrong party is what leaves requests open indefinitely.
 */
class TravelReconciliationController extends Controller
{
    public function __construct(private readonly TravelReconciliationService $service) {}

    /**
     * Whether this caller may evidence this particular line.
     *
     * Two questions, and both matter: can they see the request at all, and is
     * this category's evidence theirs to give.
     */
    private function assertMayEvidence(Request $request, TravelRequest $travelRequest, TravelRequestItem $item): void
    {
        $this->authorize('view', $travelRequest);

        $employeeId = $request->user()->employeeId();
        $isTraveller = $employeeId !== null && $employeeId === $travelRequest->employee_id;
        $isFinance   = $request->user()->hasPermission(Permission::TRAVEL_ASSESS_FINANCE);

        $category = $item->category;

        if (! $category || $category->evidence_type === 'none') {
            throw ValidationException::withMessages([
                'item' => 'This expense does not require evidence.',
            ]);
        }

        if (! $category->acceptsEvidenceFrom($isTraveller, $isFinance)) {
            throw ValidationException::withMessages([
                'item' => $category->evidence_provider === 'finance'
                    ? 'Evidence for ' . $category->name . ' is provided by finance.'
                    : 'Evidence for ' . $category->name . ' is provided by the traveller.',
            ]);
        }
    }

    public function storeEvidence(Request $request, TravelRequest $travelRequest, TravelRequestItem $item): TravelRequestResource
    {
        abort_unless($item->travel_request_id === $travelRequest->id, 404);
        $this->assertMayEvidence($request, $travelRequest, $item);

        $data = $request->validate([
            'kind' => ['required', Rule::in(TravelExpenseEvidence::KINDS)],
            // Required, not optional: a receipt nobody explained cannot be
            // checked without asking the person who uploaded it.
            'explanation' => ['required', 'string', 'min:3', 'max:2000'],
            'amount'      => ['nullable', 'numeric', 'min:0'],
            'receipt'     => ['required_if:kind,receipt', 'file',
                              'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ]);

        try {
            if ($data['kind'] === 'receipt') {
                $this->service->attachReceipt(
                    item: $item,
                    file: $request->file('receipt'),
                    explanation: $data['explanation'],
                    amount: isset($data['amount']) ? (float) $data['amount'] : null,
                    employeeId: $request->user()->employeeId(),
                );
            } else {
                $this->service->acknowledge($item, $data['explanation'], $request->user()->employeeId());
            }
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return new TravelRequestResource(
            $travelRequest->fresh(['items.category', 'items.evidence.submittedBy', 'activity.actor'])
        );
    }

    /**
     * Stream a receipt to someone entitled to see the request.
     *
     * Through the application rather than a public URL: a receipt names a
     * person, a place and a date, and often carries part of a card number.
     */
    public function downloadEvidence(TravelRequest $travelRequest, TravelExpenseEvidence $evidence): StreamedResponse
    {
        $this->authorize('view', $travelRequest);
        abort_unless($evidence->item?->travel_request_id === $travelRequest->id, 404);
        abort_if($evidence->storage_path === null, 404, 'This evidence is an acknowledgement, not a document.');

        $disk = Storage::disk('travel_evidence');
        abort_unless($disk->exists($evidence->storage_path), 404, 'File not found.');

        return response()->streamDownload(
            function () use ($disk, $evidence) {
                $stream = $disk->readStream($evidence->storage_path);
                fpassthru($stream);
                if (is_resource($stream)) fclose($stream);
            },
            $evidence->original_name ?? 'receipt',
            [
                'Content-Type'           => $evidence->mime_type ?? 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control'          => 'no-store',
            ],
        );
    }

    public function destroyEvidence(TravelRequest $travelRequest, TravelExpenseEvidence $evidence): TravelRequestResource
    {
        $this->authorize('view', $travelRequest);
        abort_unless($evidence->item?->travel_request_id === $travelRequest->id, 404);

        // Soft deleted: an incorrect receipt that was submitted is still part
        // of what happened, and a hard delete would remove the trail of it.
        $evidence->delete();

        return new TravelRequestResource(
            $travelRequest->fresh(['items.category', 'items.evidence.submittedBy'])
        );
    }

    /** What is still outstanding, and whose job it is. */
    public function outstanding(TravelRequest $travelRequest): JsonResponse
    {
        $this->authorize('view', $travelRequest);

        return response()->json([
            'data' => $this->service->outstanding($travelRequest->load('items.category')),
        ]);
    }

    /**
     * Close the trip.
     *
     * Finance closes, not the traveller: closing is the statement that the
     * money was accounted for, and the person who spent it should not be the
     * one certifying that.
     */
    public function close(Request $request, TravelRequest $travelRequest): TravelRequestResource
    {
        $this->authorize('view', $travelRequest);

        abort_unless(
            $request->user()->hasPermission(Permission::TRAVEL_ASSESS_FINANCE),
            403,
            'Only finance can close a travel request.',
        );

        try {
            $closed = $this->service->close($travelRequest, $request->user()->employeeId());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return new TravelRequestResource($closed->load(['items.category', 'items.evidence', 'activity.actor']));
    }
}
