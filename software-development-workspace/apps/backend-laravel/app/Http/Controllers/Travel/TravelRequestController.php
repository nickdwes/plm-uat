<?php

namespace App\Http\Controllers\Travel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Travel\StoreTravelRequest;
use App\Http\Resources\Travel\TravelRequestResource;
use App\Models\Travel\TravelRequest;
use App\Services\Travel\TravelRequestService;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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

        try {
            $created = $this->service->create(
                data: $request->validated(),
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

    public function destroy(TravelRequest $travelRequest): JsonResponse
    {
        $this->authorize('update', $travelRequest);
        $travelRequest->delete();

        return response()->json(['message' => 'Travel request withdrawn.']);
    }
}
