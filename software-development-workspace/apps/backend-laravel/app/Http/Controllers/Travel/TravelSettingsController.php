<?php

namespace App\Http\Controllers\Travel;

use App\Http\Controllers\Controller;
use App\Models\Travel\TravelAllowanceRate;
use App\Models\Travel\TravelApprovalStage;
use App\Models\Travel\TravelDestination;
use App\Models\Travel\TravelExpenseCategory;
use App\Models\Travel\TravelRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    // ── Approval stages ───────────────────────────────────────────────────

    public function stages(): JsonResponse
    {
        $this->authorizeSettings();

        return response()->json(['data' => TravelApprovalStage::orderBy('sequence')->get()]);
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

        foreach ($data['stages'] as $stage) {
            TravelApprovalStage::updateOrCreate(
                ['organization_id' => $this->orgId(), 'stage' => $stage['stage']],
                [
                    'sequence'              => $stage['sequence'],
                    'required_above_amount' => $stage['required_above_amount'] ?? null,
                    'currency'              => $stage['currency'] ?? 'KES',
                    'is_active'             => $stage['is_active'] ?? true,
                ],
            );
        }

        return response()->json(['data' => TravelApprovalStage::orderBy('sequence')->get()]);
    }
}
