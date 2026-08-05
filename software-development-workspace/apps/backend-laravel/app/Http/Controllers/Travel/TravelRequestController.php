<?php

namespace App\Http\Controllers\Travel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Travel\StoreTravelRequest;
use App\Http\Resources\Travel\TravelRequestResource;
use App\Models\Travel\TravelRequest;
use App\Models\Travel\TravelItineraryItem;
use App\Models\Travel\TravelRequestItem;
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

        $q = TravelRequest::with(['employee', 'destination', 'account', 'approvals'])
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

        foreach (['status', 'employee_id', 'account_id', 'travel_destination_id'] as $filter) {
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
            $travelRequest->load(['items.category', 'approvals.expectedApprover', 'employee', 'destination', 'account'])
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

    public function destroy(TravelRequest $travelRequest): JsonResponse
    {
        $this->authorize('update', $travelRequest);
        $travelRequest->delete();

        return response()->json(['message' => 'Travel request withdrawn.']);
    }
}
