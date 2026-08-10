<?php

namespace App\Http\Controllers\Travel;

use App\Support\Storage\DocumentVault;
use App\Http\Controllers\Controller;
use App\Http\Resources\Travel\TravelRequestResource;
use App\Models\Delivery\DeliveryCourier;
use App\Models\Delivery\DeliveryManifestItem;
use App\Models\Travel\TravelAttachment;
use App\Models\Travel\TravelRequest;
use App\Services\Travel\TravelPlacementResolver;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Deliveries: the manifest, who is carrying it, and the note it closes against.
 *
 * A delivery is coordinated by finance from the procurement side rather than
 * raised by whoever happens to be driving. It carries a manifest of what is in
 * the vehicle, it is signed for on arrival line by line, and it closes against
 * a delivery note rather than a fuel receipt.
 *
 * The underlying request is a travel request, so approvals, costing and
 * tenancy are the ones already built and tested. What differs is what hangs
 * off it.
 */
class DeliveryController extends Controller
{
    public function __construct(private readonly TravelPlacementResolver $placement) {}

    private function loaded(TravelRequest $request): TravelRequestResource
    {
        return new TravelRequestResource($request->fresh([
            'manifest', 'couriers.employee', 'attachments',
            'transportLegs.vehicleType', 'employee', 'destination', 'approvals',
        ]));
    }

    // ── Manifest ──────────────────────────────────────────────────────────

    public function storeItem(Request $request, TravelRequest $travelRequest): TravelRequestResource
    {
        $this->authorize('update', $travelRequest);

        $data = $request->validate([
            'description'    => ['required', 'string', 'max:500'],
            'quantity'       => ['sometimes', 'numeric', 'min:0.01'],
            'unit'           => ['sometimes', 'string', 'max:24'],
            'weight_kg'      => ['nullable', 'numeric', 'min:0'],
            'dimensions'     => ['nullable', 'string', 'max:120'],
            // Serial, asset tag or LPO — whatever ties this line to the
            // paperwork it arrived with.
            'reference'      => ['nullable', 'string', 'max:120'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],
            'notes'          => ['nullable', 'string', 'max:1000'],
        ]);

        $travelRequest->manifest()->create($data);

        return $this->loaded($travelRequest);
    }

    public function updateItem(Request $request, TravelRequest $travelRequest, DeliveryManifestItem $item): TravelRequestResource
    {
        abort_unless($item->travel_request_id === $travelRequest->id, 404);

        /*
         * Signing a line off happens on arrival, when the request is no longer
         * editable. So receiving is gated on being able to see the delivery,
         * while changing what was sent needs edit rights.
         */
        $receiving = collect($request->keys())
            ->diff(['quantity_received', 'condition_on_arrival', 'notes', 'org'])
            ->isEmpty();

        $this->authorize($receiving ? 'view' : 'update', $travelRequest);

        $data = $request->validate([
            'description'    => ['sometimes', 'string', 'max:500'],
            'quantity'       => ['sometimes', 'numeric', 'min:0.01'],
            'unit'           => ['sometimes', 'string', 'max:24'],
            'weight_kg'      => ['nullable', 'numeric', 'min:0'],
            'dimensions'     => ['nullable', 'string', 'max:120'],
            'reference'      => ['nullable', 'string', 'max:120'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],
            // Null means "not yet signed for", which is a different state from
            // signed for as zero — and the difference decides whether anybody
            // is still waiting for it.
            'quantity_received'    => ['nullable', 'numeric', 'min:0'],
            'condition_on_arrival' => ['nullable', Rule::in(['good', 'damaged', 'partial'])],
            'notes'                => ['nullable', 'string', 'max:1000'],
        ]);

        $item->update($data);

        if ($receiving && array_key_exists('quantity_received', $data)) {
            $travelRequest->recordActivity(
                'received',
                trim(($data['quantity_received'] ?? 0) . ' × ' . $item->description
                    . ($data['condition_on_arrival'] ?? '' ? ' (' . $data['condition_on_arrival'] . ')' : '')),
                $request->user()->employeeId(),
            );
        }

        return $this->loaded($travelRequest);
    }

    public function destroyItem(TravelRequest $travelRequest, DeliveryManifestItem $item): TravelRequestResource
    {
        $this->authorize('update', $travelRequest);
        abort_unless($item->travel_request_id === $travelRequest->id, 404);

        $item->delete();

        return $this->loaded($travelRequest);
    }

    // ── Couriers ──────────────────────────────────────────────────────────

    public function storeCourier(Request $request, TravelRequest $travelRequest): TravelRequestResource
    {
        $this->authorize('update', $travelRequest);

        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'is_primary'  => ['sometimes', 'boolean'],
        ]);

        // Somebody carrying a consignment for this organisation must belong to
        // it — tenancy scoping does not supply that on its own.
        if (! $this->placement->isMember($data['employee_id'], $travelRequest->organization_id)) {
            throw ValidationException::withMessages([
                'employee_id' => 'That employee is not a member of this organisation.',
            ]);
        }

        // One person is accountable for the consignment, as distinct from
        // whoever came along to help carry it.
        if (! empty($data['is_primary'])) {
            $travelRequest->couriers()->update(['is_primary' => false]);
        }

        DeliveryCourier::updateOrCreate(
            ['travel_request_id' => $travelRequest->id, 'employee_id' => $data['employee_id']],
            ['is_primary' => $data['is_primary'] ?? ! $travelRequest->couriers()->exists()],
        );

        return $this->loaded($travelRequest);
    }

    public function destroyCourier(TravelRequest $travelRequest, DeliveryCourier $courier): TravelRequestResource
    {
        $this->authorize('update', $travelRequest);
        abort_unless($courier->travel_request_id === $travelRequest->id, 404);

        $wasPrimary = $courier->is_primary;
        $courier->delete();

        // A consignment with couriers but nobody accountable is the state that
        // makes "who signed for this" unanswerable.
        if ($wasPrimary) {
            $travelRequest->couriers()->oldest('id')->first()?->update(['is_primary' => true]);
        }

        return $this->loaded($travelRequest);
    }

    // ── Documents ─────────────────────────────────────────────────────────

    /**
     * Upload a delivery note, quotation or other supporting document.
     *
     * The type is recorded because a delivery closes against a signed note.
     * Without it "has an attachment" stands in for "was delivered", and a
     * quotation would satisfy the check.
     */
    public function storeAttachment(Request $request, TravelRequest $travelRequest): TravelRequestResource
    {
        // A delivery note arrives after the trip, when the request is no
        // longer editable, so this is gated on seeing it.
        $this->authorize('view', $travelRequest);

        $data = $request->validate([
            'document_type' => ['required', Rule::in(TravelAttachment::TYPES)],
            'file'          => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $file = $request->file('file');
        $path = "{$travelRequest->organization_id}/{$travelRequest->id}/"
            . Str::uuid() . '.' . ($file->getClientOriginalExtension() ?: 'bin');

        // Sealed on the way in, like every other class on the encrypted
            // list. This wrote the receipt untouched.
            DocumentVault::putContents(
                'travel_evidence', $path, (string) file_get_contents($file->getRealPath()),
            );

        $attachment = $travelRequest->attachments()->create([
            'document_type' => $data['document_type'],
            'original_name' => $file->getClientOriginalName(),
            'storage_path'  => $path,
            'mime_type'     => $file->getMimeType() ?? $file->getClientMimeType(),
            'size_bytes'    => $file->getSize(),
            'uploaded_by'   => $request->user()->id,
        ]);

        // The note is what the delivery closes against, so it is named on the
        // request rather than left to be found among the attachments.
        if ($data['document_type'] === 'delivery_note') {
            $travelRequest->update(['delivery_note_attachment_id' => $attachment->id]);
            $travelRequest->recordActivity('delivered', 'Delivery note uploaded', $request->user()->employeeId());
        }

        return $this->loaded($travelRequest);
    }

    public function downloadAttachment(TravelRequest $travelRequest, TravelAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $travelRequest);
        abort_unless($attachment->travel_request_id === $travelRequest->id, 404);

        $disk = Storage::disk('travel_evidence');
        abort_unless($disk->exists($attachment->storage_path), 404, 'File not found.');

        return response()->streamDownload(
            function () use ($disk, $attachment) {
                $stream = $disk->readStream($attachment->storage_path);
                fpassthru($stream);
                if (is_resource($stream)) fclose($stream);
            },
            $attachment->original_name,
            [
                'Content-Type'           => $attachment->mime_type ?? 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control'          => 'no-store',
            ],
        );
    }

    /** What this delivery still needs before it can be closed. */
    public function outstanding(TravelRequest $travelRequest): JsonResponse
    {
        $this->authorize('view', $travelRequest);

        return response()->json([
            'data' => $travelRequest->load('manifest')->deliveryGaps(),
        ]);
    }
}
