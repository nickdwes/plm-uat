<?php

namespace App\Http\Controllers\Travel;

use App\Http\Controllers\Controller;
use App\Models\Travel\TransportBusFare;
use App\Models\Travel\TransportMileageRate;
use App\Models\Travel\TransportVehicleType;
use App\Models\Travel\TravelAllowanceRate;
use App\Models\Travel\TravelApprovalStage;
use App\Models\Travel\TravelDestination;
use App\Models\Travel\TravelExpenseCategory;
use App\Models\Travel\TravelRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Travel configuration: destinations, expense categories, the allowance rate
 * card and the approval chain.
 *
 * All of it is organisation-scoped, and all of it is gated on
 * `travel.manage_rates` — held by Finance, whose instrument the rate card is.
 *
 * Nothing here can be reached without a current organisation, so one tenant
 * cannot read or edit another's rates. The tenancy scope on each model does
 * the filtering; `organization_id` is written from context rather than input,
 * because a caller-supplied organisation is a caller-chosen one.
 */
class TravelSettingsController extends Controller
{
    private function authorizeSettings(): void
    {
        $this->authorize('manageReferenceData', TravelRequest::class);
    }

    private function orgId(): int
    {
        return app('current.organization_id');
    }

    // ── Destinations ──────────────────────────────────────────────────────

    public function destinations(): JsonResponse
    {
        $this->authorizeSettings();

        return response()->json(['data' => TravelDestination::orderBy('name')->get()]);
    }

    public function storeDestination(Request $request): JsonResponse
    {
        $this->authorizeSettings();

        $data = $request->validate([
            'name'             => ['required', 'string', 'max:255',
                Rule::unique('travel_destinations', 'name')->where('organization_id', $this->orgId())],
            'country'          => ['nullable', 'string', 'size:2'],
            'is_international' => ['sometimes', 'boolean'],
            'is_active'        => ['sometimes', 'boolean'],
        ]);

        $destination = TravelDestination::create($data + ['organization_id' => $this->orgId()]);

        return response()->json(['data' => $destination], 201);
    }

    public function updateDestination(Request $request, TravelDestination $destination): JsonResponse
    {
        $this->authorizeSettings();

        $data = $request->validate([
            'name'             => ['sometimes', 'string', 'max:255',
                Rule::unique('travel_destinations', 'name')
                    ->where('organization_id', $this->orgId())->ignore($destination->id)],
            'country'          => ['nullable', 'string', 'size:2'],
            'is_international' => ['sometimes', 'boolean'],
            'is_active'        => ['sometimes', 'boolean'],
        ]);

        $destination->update($data);

        return response()->json(['data' => $destination->fresh()]);
    }

    /**
     * Retire a destination rather than delete it.
     *
     * Requests already raised against it keep a foreign key, and a deleted row
     * would leave those showing no destination at all. Deactivating removes it
     * from the picker and leaves history readable.
     */
    public function destroyDestination(TravelDestination $destination): JsonResponse
    {
        $this->authorizeSettings();
        $destination->update(['is_active' => false]);

        return response()->json(['message' => 'Destination retired.']);
    }

    // ── Expense categories ────────────────────────────────────────────────

    public function categories(): JsonResponse
    {
        $this->authorizeSettings();

        return response()->json(['data' => TravelExpenseCategory::orderBy('name')->get()]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $this->authorizeSettings();

        $data = $request->validate([
            'name'             => ['required', 'string', 'max:255',
                Rule::unique('travel_expense_categories', 'name')->where('organization_id', $this->orgId())],
            'code'             => ['nullable', 'string', 'max:32'],
            'requires_receipt' => ['sometimes', 'boolean'],
            // What closing a line of this category needs, and whose job it is
            // to supply it. Asking the wrong party is what leaves requests
            // open indefinitely.
            'evidence_type'     => ['sometimes', Rule::in(TravelExpenseCategory::EVIDENCE_TYPES)],
            'evidence_provider' => ['sometimes', Rule::in(TravelExpenseCategory::EVIDENCE_PROVIDERS)],
            'is_active'        => ['sometimes', 'boolean'],
        ]);

        $category = TravelExpenseCategory::create($data + ['organization_id' => $this->orgId()]);

        return response()->json(['data' => $category], 201);
    }

    public function updateCategory(Request $request, TravelExpenseCategory $category): JsonResponse
    {
        $this->authorizeSettings();

        $data = $request->validate([
            'name'             => ['sometimes', 'string', 'max:255',
                Rule::unique('travel_expense_categories', 'name')
                    ->where('organization_id', $this->orgId())->ignore($category->id)],
            'code'             => ['nullable', 'string', 'max:32'],
            'requires_receipt' => ['sometimes', 'boolean'],
            // What closing a line of this category needs, and whose job it is
            // to supply it. Asking the wrong party is what leaves requests
            // open indefinitely.
            'evidence_type'     => ['sometimes', Rule::in(TravelExpenseCategory::EVIDENCE_TYPES)],
            'evidence_provider' => ['sometimes', Rule::in(TravelExpenseCategory::EVIDENCE_PROVIDERS)],
            'is_active'        => ['sometimes', 'boolean'],
        ]);

        $category->update($data);

        return response()->json(['data' => $category->fresh()]);
    }

    public function destroyCategory(TravelExpenseCategory $category): JsonResponse
    {
        $this->authorizeSettings();
        $category->update(['is_active' => false]);

        return response()->json(['message' => 'Category retired.']);
    }

    /**
     * Job grades, for pricing the rate card by seniority.
     *
     * Served here rather than from the org endpoint, which requires
     * `departments.view` — a permission finance has no other reason to hold.
     * Whoever sets what a grade is paid to travel needs to see the grades;
     * that is not the same as needing to manage the org chart.
     */
    public function jobGrades(): JsonResponse
    {
        $this->authorizeSettings();

        return response()->json([
            'data' => DB::table('job_grades')
                ->where('organization_id', $this->orgId())
                ->orderBy('level')
                ->get(['id', 'name', 'code', 'level']),
        ]);
    }

    // ── Allowance rates ───────────────────────────────────────────────────

    public function rates(Request $request): JsonResponse
    {
        $this->authorizeSettings();

        $q = TravelAllowanceRate::with(['category'])
            ->orderBy('travel_expense_category_id')
            ->orderByDesc('effective_from');

        // Superseded rates are kept, so the default view is what is in force
        // today and history is opt-in.
        if (! $request->boolean('include_history')) {
            $q->effectiveOn(now()->toDateString());
        }

        return response()->json(['data' => $q->get()]);
    }

    public function storeRate(Request $request): JsonResponse
    {
        $this->authorizeSettings();

        $data = $this->validateRate($request);

        return response()->json(['data' => TravelAllowanceRate::create($data + ['organization_id' => $this->orgId()])], 201);
    }

    /**
     * Change a rate by superseding it, never by editing it in place.
     *
     * A request approved in March must still show March's rates in November.
     * Editing the amount on the existing row would rewrite what every past
     * request was costed against — invisible until someone audits a payment.
     * The old row is closed the day before the new one starts, and a new row
     * carries the new amount.
     */
    public function updateRate(Request $request, TravelAllowanceRate $rate): JsonResponse
    {
        $this->authorizeSettings();

        $data = $this->validateRate($request);

        if ($data['effective_from'] <= $rate->effective_from->toDateString()) {
            throw ValidationException::withMessages([
                'effective_from' => 'A new rate must start after the one it replaces. '
                    . 'To correct a mistake, retire this rate and add the right one.',
            ]);
        }

        $rate->update([
            'effective_to' => \Carbon\Carbon::parse($data['effective_from'])->subDay()->toDateString(),
        ]);

        $replacement = TravelAllowanceRate::create($data + ['organization_id' => $this->orgId()]);

        return response()->json([
            'data'    => $replacement,
            'message' => 'Previous rate closed on ' . $rate->fresh()->effective_to->toDateString() . '.',
        ]);
    }

    /** Close a rate rather than delete it, so past costings stay explicable. */
    public function destroyRate(TravelAllowanceRate $rate): JsonResponse
    {
        $this->authorizeSettings();

        $rate->update(['effective_to' => now()->toDateString()]);

        return response()->json(['message' => 'Rate closed today. Requests already costed against it are unchanged.']);
    }

    private function validateRate(Request $request): array
    {
        $orgId = $this->orgId();

        return $request->validate([
            'travel_expense_category_id' => ['required', 'integer',
                Rule::exists('travel_expense_categories', 'id')->where('organization_id', $orgId)],
            // Null means "applies to every grade" and "every destination",
            // which is what lets one rate be stated globally and overridden
            // for a few cases rather than enumerating every combination.
            'job_grade_id' => ['nullable', 'integer',
                Rule::exists('job_grades', 'id')->where('organization_id', $orgId)],
            'travel_destination_id' => ['nullable', 'integer',
                Rule::exists('travel_destinations', 'id')->where('organization_id', $orgId)],
            'amount'         => ['required', 'numeric', 'min:0'],
            'currency'       => ['sometimes', 'string', 'size:3'],
            'unit'           => ['sometimes', Rule::in(['per_day', 'per_trip'])],
            'effective_from' => ['required', 'date'],
            'effective_to'   => ['nullable', 'date', 'after:effective_from'],
        ]);
    }


    // ── Transport ─────────────────────────────────────────────────────────
    //
    // Transport is computed from distance, fuel and mode rather than looked up
    // against a grade. What is configured here is the arithmetic; the journey
    // supplies the rest.
    //
    // Fuel price is deliberately not here. It is entered per request, because
    // a pump price held centrally is stale within a week and every journey
    // would be costed against a number nobody updated.

    public function mileageRates(Request $request): JsonResponse
    {
        $this->authorizeSettings();

        $q = TransportMileageRate::orderByDesc('effective_from');

        if (! $request->boolean('include_history')) {
            $today = now()->toDateString();
            $q->whereDate('effective_from', '<=', $today)
              ->where(fn ($w) => $w->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today));
        }

        return response()->json(['data' => $q->get()]);
    }

    public function storeMileageRate(Request $request): JsonResponse
    {
        $this->authorizeSettings();

        $data = $request->validate([
            'rate_per_km'    => ['required', 'numeric', 'min:0'],
            'currency'       => ['sometimes', 'string', 'size:3'],
            'effective_from' => ['required', 'date'],
        ]);

        /*
         * A new rate closes the one it replaces.
         *
         * Two rates in force at once would make the calculator pick one
         * silently, and a reimbursement nobody can reproduce is worse than one
         * that is merely wrong.
         */
        TransportMileageRate::whereNull('effective_to')
            ->whereDate('effective_from', '<', $data['effective_from'])
            ->update(['effective_to' => \Carbon\Carbon::parse($data['effective_from'])->subDay()->toDateString()]);

        return response()->json([
            'data' => TransportMileageRate::create($data + ['organization_id' => $this->orgId()]),
        ], 201);
    }

    public function vehicleTypes(): JsonResponse
    {
        $this->authorizeSettings();

        return response()->json(['data' => TransportVehicleType::orderBy('name')->get()]);
    }

    public function storeVehicleType(Request $request): JsonResponse
    {
        $this->authorizeSettings();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120',
                Rule::unique('transport_vehicle_types', 'name')->where('organization_id', $this->orgId())],
            // Without this a hired vehicle cannot be costed at all, so it is
            // worth prompting for even though the schema allows null.
            'km_per_litre'     => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'load_capacity_kg' => ['nullable', 'numeric', 'min:0'],
            'is_active'        => ['sometimes', 'boolean'],
        ]);

        return response()->json([
            'data' => TransportVehicleType::create($data + ['organization_id' => $this->orgId()]),
        ], 201);
    }

    public function updateVehicleType(Request $request, TransportVehicleType $vehicleType): JsonResponse
    {
        $this->authorizeSettings();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120',
                Rule::unique('transport_vehicle_types', 'name')
                    ->where('organization_id', $this->orgId())->ignore($vehicleType->id)],
            'km_per_litre'     => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'load_capacity_kg' => ['nullable', 'numeric', 'min:0'],
            'is_active'        => ['sometimes', 'boolean'],
        ]);

        $vehicleType->update($data);

        return response()->json(['data' => $vehicleType->fresh()]);
    }

    public function destroyVehicleType(TransportVehicleType $vehicleType): JsonResponse
    {
        $this->authorizeSettings();
        // Retired, not deleted: legs already costed against it hold a foreign
        // key and would otherwise show no vehicle at all.
        $vehicleType->update(['is_active' => false]);

        return response()->json(['message' => 'Vehicle type retired.']);
    }

    public function busFares(): JsonResponse
    {
        $this->authorizeSettings();

        return response()->json(['data' => TransportBusFare::with('destination')->get()]);
    }

    public function saveBusFare(Request $request): JsonResponse
    {
        $this->authorizeSettings();

        $data = $request->validate([
            'travel_destination_id' => ['required', 'integer',
                Rule::exists('travel_destinations', 'id')->where('organization_id', $this->orgId())],
            'round_trip_amount' => ['required', 'numeric', 'min:0'],
            'currency'          => ['sometimes', 'string', 'size:3'],
            'is_active'         => ['sometimes', 'boolean'],
        ]);

        // One fare per route, so setting it again replaces it. A published
        // price has no history worth keeping the way a rate card does.
        $fare = TransportBusFare::updateOrCreate(
            [
                'organization_id'       => $this->orgId(),
                'travel_destination_id' => $data['travel_destination_id'],
            ],
            $data,
        );

        return response()->json(['data' => $fare->load('destination')]);
    }

    public function destroyBusFare(TransportBusFare $busFare): JsonResponse
    {
        $this->authorizeSettings();
        $busFare->delete();

        return response()->json(['message' => 'Fare removed.']);
    }

    // ── Approval stages ───────────────────────────────────────────────────

    public function stages(Request $request): JsonResponse
    {
        $this->authorizeSettings();

        $q = TravelApprovalStage::orderBy('sequence');

        // A type is asked for explicitly. Returning every chain at once would
        // make the caller separate them, and the screen edits one at a time.
        $type = $request->input('travel_type');
        $type ? $q->where('travel_type', $type) : $q->whereNull('travel_type');

        return response()->json(['data' => $q->get()]);
    }

    /**
     * Replace the whole chain in one call.
     *
     * Sent together rather than one stage at a time because the chain only
     * makes sense as a set: saving stages individually can leave it briefly
     * empty, and an empty chain is a request that approves itself.
     */
    public function saveStages(Request $request): JsonResponse
    {
        $this->authorizeSettings();

        $data = $request->validate([
            // Null is the default chain, used by any journey type without one
            // of its own. A delivery chain replaces it rather than adding to
            // it, because a delivery is not approved by a line manager.
            'travel_type'                     => ['nullable', Rule::in(\App\Models\Travel\TravelRequest::TRAVEL_TYPES)],
            'stages'                          => ['required', 'array', 'min:1'],
            'stages.*.stage'                  => ['required', Rule::in(TravelApprovalStage::STAGES)],
            'stages.*.sequence'               => ['required', 'integer', 'min:1'],
            'stages.*.required_above_amount'  => ['nullable', 'numeric', 'min:0'],
            'stages.*.currency'               => ['sometimes', 'string', 'size:3'],
            'stages.*.is_active'              => ['sometimes', 'boolean'],
        ]);

        $active = collect($data['stages'])->filter(fn ($s) => $s['is_active'] ?? true);

        // A chain where every stage carries a threshold can resolve to nothing
        // for a small request, which would let it approve itself. At least one
        // stage must apply unconditionally.
        if ($active->isEmpty() || $active->every(fn ($s) => ($s['required_above_amount'] ?? null) !== null)) {
            throw ValidationException::withMessages([
                'stages' => 'At least one active stage must apply to every request. '
                    . 'Leave the threshold blank on the first stage, or a small request would be approved by nobody.',
            ]);
        }

        if ($active->pluck('stage')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['stages' => 'Each stage may appear only once.']);
        }

        $type = $data['travel_type'] ?? null;

        foreach ($data['stages'] as $stage) {
            TravelApprovalStage::updateOrCreate(
                ['organization_id' => $this->orgId(), 'stage' => $stage['stage'], 'travel_type' => $type],
                [
                    'sequence'              => $stage['sequence'],
                    'required_above_amount' => $stage['required_above_amount'] ?? null,
                    'currency'              => $stage['currency'] ?? 'KES',
                    'is_active'             => $stage['is_active'] ?? true,
                ],
            );
        }

        return response()->json([
            'data' => TravelApprovalStage::where('travel_type', $type)->orderBy('sequence')->get(),
        ]);
    }
}
