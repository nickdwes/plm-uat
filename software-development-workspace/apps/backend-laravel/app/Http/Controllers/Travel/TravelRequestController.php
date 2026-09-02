<?php

namespace App\Http\Controllers\Travel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Travel\StoreTravelRequest;
use App\Http\Resources\Travel\TravelRequestResource;
use App\Models\Travel\TravelRequest;
use App\Models\Travel\TravelBooking;
use App\Models\Travel\TravelItineraryItem;
use App\Models\Travel\TravelTransportLeg;
use App\Models\Travel\TravelRequestItem;
use App\Services\Travel\TransportCalculator;
use App\Services\Travel\TravelRequestService;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TravelRequestController extends Controller
{
    public function __construct(private readonly TravelRequestService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TravelRequest::class);

        $q = TravelRequest::with(['employee', 'destination', 'account', 'project:id,name', 'approvals'])
            ->orderByDesc('created_at');

        // Narrowed from the authenticated user, never from input: reading the
        // scope from a parameter would let a caller ask for someone else's.
        if (! $request->user()->hasPermission(Permission::TRAVEL_VIEW)) {
            $employeeId = $request->user()->employeeId();

            $q->where(function ($w) use ($request, $employeeId) {
                $w->where('employee_id', $employeeId);

                // An approver sees what is waiting on them even without a
                // broader view permission — otherwise the queue is invisible
                // to the person who has to clear it.
                $w->orWhereHas('approvals', fn ($a) => $a->where('expected_approver_id', $employeeId));

                if ($request->user()->hasPermission(Permission::TRAVEL_VIEW_TEAM)) {
                    $w->orWhere('department_id', $request->user()->employee?->department_id);
                }
            });
        }

        // travel_type included so the deliveries screen asks for deliveries
        // rather than filtering a full list in the browser.
        foreach (['status', 'travel_type', 'employee_id', 'account_id', 'travel_destination_id', 'project_id'] as $filter) {
            if ($request->filled($filter)) {
                $q->where($filter, $request->input($filter));
            }
        }

        return TravelRequestResource::collection($q->paginate((int) $request->input('per_page', 20)));
    }

    public function store(StoreTravelRequest $request): JsonResponse
    {
        $this->authorize('create', TravelRequest::class);

        $data = $request->validated();
        $self = $request->user()->employeeId();

        // Default to the caller. Naming someone else is raising a request on
        // their behalf, which is a different act and needs travel.manage —
        // otherwise anyone could commit a colleague's budget.
        if (! array_key_exists('employee_id', $data) || (int) $data['employee_id'] === $self) {
            $data['employee_id'] = $self;
        } elseif (! $request->user()->hasPermission(Permission::TRAVEL_MANAGE)) {
            throw ValidationException::withMessages([
                'employee_id' => 'You can only raise travel requests for yourself.',
            ]);
        }

        if (! $data['employee_id']) {
            throw ValidationException::withMessages([
                'employee_id' => 'Your user account is not linked to an employee record, so travel cannot be raised.',
            ]);
        }

        try {
            $created = $this->service->create(
                data: $data,
                organizationId: app('current.organization_id'),
                actorId: $request->user()->id,
            );
        } catch (RuntimeException $e) {
            // Surfaced as a validation error rather than a 500: "that employee
            // is not a member of this organisation" is something the caller
            // can correct, not a fault.
            throw ValidationException::withMessages(['employee_id' => $e->getMessage()]);
        }

        // 201 stated rather than inferred: Laravel derives it from
        // `wasRecentlyCreated`, which is lost the moment the model is
        // re-fetched — as it is here, after costing.
        return (new TravelRequestResource($created->load(['items', 'employee', 'destination'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(TravelRequest $travelRequest): TravelRequestResource
    {
        $this->authorize('view', $travelRequest);

        return new TravelRequestResource(
            $travelRequest->load([
                'items.category', 'items.evidence.submittedBy', 'transportLegs.vehicleType',
                'approvals.expectedApprover', 'approvals.request',
                'employee', 'destination', 'account',
                'itinerary', 'bookings', 'attachments', 'activity.actor',
            ])
        );
    }

    public function update(StoreTravelRequest $request, TravelRequest $travelRequest): TravelRequestResource
    {
        $this->authorize('update', $travelRequest);

        try {
            $updated = $this->service->update($travelRequest, $request->validated());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return new TravelRequestResource($updated->load(['items', 'employee', 'destination']));
    }

    public function submit(TravelRequest $travelRequest): TravelRequestResource
    {
        $this->authorize('submit', $travelRequest);

        try {
            $submitted = $this->service->submit($travelRequest);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return new TravelRequestResource($submitted->load(['items', 'approvals']));
    }

    /**
     * Approve or reject the stage the request is currently waiting on.
     *
     * One endpoint for all four stages: which stage is being decided is a
     * property of the request, not something the caller states. Letting the
     * caller name it would allow a later approver to decide an earlier stage.
     */
    public function decide(Request $request, TravelRequest $travelRequest): TravelRequestResource
    {
        $this->authorize('decide', $travelRequest);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'comment'  => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $decided = $this->service->decide(
                request: $travelRequest,
                decision: $data['decision'],
                employeeId: $request->user()->employeeId(),
                comment: $data['comment'] ?? null,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['decision' => $e->getMessage()]);
        }

        return new TravelRequestResource($decided->load(['approvals.expectedApprover', 'items']));
    }

    // ── Estimated expenses ────────────────────────────────────────────────

    public function storeItem(Request $request, TravelRequest $travelRequest): TravelRequestResource
    {
        $this->authorize('update', $travelRequest);

        $data = $request->validate([
            'travel_expense_category_id' => ['required', 'integer',
                Rule::exists('travel_expense_categories', 'id')
                    ->where('organization_id', app('current.organization_id'))],
            'description'    => ['nullable', 'string', 'max:500'],
            'quantity'       => ['sometimes', 'numeric', 'min:0.01'],
            'unit'           => ['sometimes', Rule::in(['per_day', 'per_trip'])],
            'amount_claimed' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $updated = $this->service->addItem($travelRequest, $data);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return new TravelRequestResource($updated->load(['items.category']));
    }

    public function updateItem(Request $request, TravelRequest $travelRequest, TravelRequestItem $item): TravelRequestResource
    {
        $this->authorize('update', $travelRequest);
        abort_unless($item->travel_request_id === $travelRequest->id, 404);

        $data = $request->validate([
            'travel_expense_category_id' => ['sometimes', 'integer',
                Rule::exists('travel_expense_categories', 'id')
                    ->where('organization_id', app('current.organization_id'))],
            'description'    => ['nullable', 'string', 'max:500'],
            'quantity'       => ['sometimes', 'numeric', 'min:0.01'],
            'unit'           => ['sometimes', Rule::in(['per_day', 'per_trip'])],
            'amount_claimed' => ['sometimes', 'numeric', 'min:0'],
        ]);

        try {
            $updated = $this->service->updateItem($travelRequest, $item, $data);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return new TravelRequestResource($updated->load(['items.category']));
    }

    public function destroyItem(TravelRequest $travelRequest, TravelRequestItem $item): TravelRequestResource
    {
        $this->authorize('update', $travelRequest);
        abort_unless($item->travel_request_id === $travelRequest->id, 404);

        try {
            $updated = $this->service->removeItem($travelRequest, $item);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return new TravelRequestResource($updated->load(['items.category']));
    }

    // ── Itinerary ─────────────────────────────────────────────────────────

    public function storeItinerary(Request $request, TravelRequest $travelRequest): TravelRequestResource
    {
        $this->authorize('update', $travelRequest);

        $data = $request->validate([
            'starts_on'      => ['required', 'date'],
            'ends_on'        => ['nullable', 'date', 'after_or_equal:starts_on'],
            'at_time'        => ['nullable', 'date_format:H:i'],
            'activity'       => ['required', 'string', 'max:255'],
            'category'       => ['nullable', 'string', 'max:64'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $travelRequest->itinerary()->create($data);

        return new TravelRequestResource($travelRequest->fresh(['itinerary', 'items.category']));
    }

    public function destroyItinerary(TravelRequest $travelRequest, TravelItineraryItem $itinerary): TravelRequestResource
    {
        $this->authorize('update', $travelRequest);
        abort_unless($itinerary->travel_request_id === $travelRequest->id, 404);

        $itinerary->delete();

        return new TravelRequestResource($travelRequest->fresh(['itinerary', 'items.category']));
    }

    // ── Bookings ──────────────────────────────────────────────────────────
    //
    // What was bought, as opposed to what was approved. Recorded after the
    // fact, so an approved request stays editable here while its estimate does
    // not.

    public function storeBooking(Request $request, TravelRequest $travelRequest): TravelRequestResource
    {
        $this->authorize('view', $travelRequest);

        $data = $request->validate([
            'booking_type'  => ['required', Rule::in(TravelBooking::TYPES)],
            'vendor'        => ['nullable', 'string', 'max:255'],
            'status'        => ['sometimes', Rule::in(['pending', 'confirmed', 'cancelled'])],
            'reference'     => ['nullable', 'string', 'max:128'],
            'from_location' => ['nullable', 'string', 'max:255'],
            'to_location'   => ['nullable', 'string', 'max:255'],
            'booking_date'  => ['nullable', 'date'],
            'total_cost'    => ['nullable', 'numeric', 'min:0'],
            'currency'      => ['sometimes', 'string', 'size:3'],
        ]);

        $travelRequest->bookings()->create($data + ['created_by' => $request->user()->id]);
        $travelRequest->recordActivity('booked',
            ucfirst($data['booking_type']) . ' booked' . (isset($data['vendor']) ? ' with ' . $data['vendor'] : ''),
            $request->user()->employeeId());

        return new TravelRequestResource($travelRequest->fresh(['bookings', 'activity.actor']));
    }

    public function destroyBooking(TravelRequest $travelRequest, TravelBooking $booking): TravelRequestResource
    {
        $this->authorize('view', $travelRequest);
        abort_unless($booking->travel_request_id === $travelRequest->id, 404);

        $booking->delete();

        return new TravelRequestResource($travelRequest->fresh(['bookings', 'activity.actor']));
    }

    // ── Transport legs ────────────────────────────────────────────────────
    //
    // A journey, by one mode. Costed from distance, fuel and mode rather than
    // from a grade allowance — a 40 km errand and a 600 km round trip are not
    // the same entitlement.

    private function legRules(): array
    {
        $orgId = app('current.organization_id');

        return [
            'mode'        => ['required', Rule::in(TransportCalculator::MODES)],
            'travel_type' => ['sometimes', Rule::in(TravelTransportLeg::TRAVEL_TYPES)],
            'purpose'     => ['nullable', 'string', 'max:255'],

            'from_location'         => ['nullable', 'string', 'max:255'],
            'to_location'           => ['nullable', 'string', 'max:255'],
            'travel_destination_id' => ['nullable', 'integer',
                Rule::exists('travel_destinations', 'id')->where('organization_id', $orgId)],

            'estimated_km'         => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'actual_km'            => ['nullable', 'numeric', 'min:0', 'max:100000'],
            // Asked for per request because a pump price held centrally is
            // stale within a week.
            'fuel_price_per_litre' => ['nullable', 'numeric', 'min:0'],
            'transport_vehicle_type_id' => ['nullable', 'integer',
                Rule::exists('transport_vehicle_types', 'id')->where('organization_id', $orgId)],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],

            'is_delivery'      => ['sometimes', 'boolean'],
            'item_description' => ['nullable', 'string', 'max:500'],
            'item_weight_kg'   => ['nullable', 'numeric', 'min:0'],
            'item_dimensions'  => ['nullable', 'string', 'max:120'],

            // Entered for the modes that cannot be estimated — a taxi fare is
            // not knowable in advance, and a ticket is a quote.
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'actual_cost'    => ['nullable', 'numeric', 'min:0'],
            'notes'          => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function storeLeg(Request $request, TravelRequest $travelRequest): TravelRequestResource
    {
        $this->authorize('update', $travelRequest);

        try {
            $updated = $this->service->addLeg($travelRequest, $request->validate($this->legRules()));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return new TravelRequestResource($updated->load(['transportLegs.vehicleType', 'items.category']));
    }

    public function updateLeg(Request $request, TravelRequest $travelRequest, TravelTransportLeg $leg): TravelRequestResource
    {
        abort_unless($leg->travel_request_id === $travelRequest->id, 404);

        // Recording distance driven happens after approval, so it is gated on
        // being able to see the request rather than edit it.
        //
        // `keys()` includes query parameters, so the org slug in the URL made
        // every update look like an estimate change.
        $reconciling = collect($request->keys())
            ->diff(['actual_km', 'actual_cost', 'org'])
            ->isEmpty();
        $this->authorize($reconciling ? 'view' : 'update', $travelRequest);

        $rules = $this->legRules();
        $rules['mode'] = ['sometimes', Rule::in(TransportCalculator::MODES)];

        try {
            $updated = $this->service->updateLeg($travelRequest, $leg, $request->validate($rules));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return new TravelRequestResource($updated->load(['transportLegs.vehicleType', 'items.category']));
    }

    public function destroyLeg(TravelRequest $travelRequest, TravelTransportLeg $leg): TravelRequestResource
    {
        $this->authorize('update', $travelRequest);
        abort_unless($leg->travel_request_id === $travelRequest->id, 404);

        try {
            $updated = $this->service->removeLeg($travelRequest, $leg);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return new TravelRequestResource($updated->load(['transportLegs.vehicleType', 'items.category']));
    }

    public function destroy(TravelRequest $travelRequest): JsonResponse
    {
        $this->authorize('update', $travelRequest);
        $travelRequest->delete();

        return response()->json(['message' => 'Travel request withdrawn.']);
    }
}
