<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Resources\Vendor\VendorResource;
use App\Models\Training\VendorPartner;
use App\Services\Vendor\VendorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VendorController extends Controller
{
    public function __construct(private readonly VendorService $service) {}

    // ─────────────────────────────────────────────────────────────────────────
    // CRUD
    // ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', VendorPartner::class);

        $vendors = $this->service->list(
            orgId:   app('current.organization_id'),
            filters: $request->only(['search', 'vendor_type', 'onboarding_status', 'is_active']),
            perPage: (int) $request->input('per_page', 25),
        );

        return VendorResource::collection($vendors);
    }

    public function store(Request $request): VendorResource
    {
        $this->authorize('create', VendorPartner::class);

        $data = $request->validate([
            'name'                        => 'required|string|max:255',
            'registration_number'         => 'nullable|string|max:100',
            'tax_id'                      => 'nullable|string|max:50',
            'vendor_type'                 => 'required|in:supplier,service_provider,contractor,consultant,distributor,partner',
            'category'                    => 'nullable|string|max:100',
            'description'                 => 'nullable|string|max:3000',
            'primary_contact_name'        => 'nullable|string|max:255',
            'primary_contact_designation' => 'nullable|string|max:255',
            'contact_email'               => 'nullable|email|max:255',
            'contact_phone'               => 'nullable|string|max:50',
            'website_url'                 => 'nullable|url|max:500',
            'physical_address'            => 'nullable|string|max:500',
            'city'                        => 'nullable|string|max:100',
            'country'                     => 'nullable|string|max:100',
            'notes'                       => 'nullable|string|max:2000',
            'is_active'                   => 'boolean',
            // Partnership & fiscal
            'partnership_level'           => 'nullable|string|max:50',
            'logo_url'                    => 'nullable|url|max:500',
            'fiscal_year_start_month'     => 'nullable|integer|min:1|max:12',
            'renewal_anniversary_date'    => 'nullable|date',
            'renewal_cost'                => 'nullable|numeric|min:0',
            'renewal_cost_currency'       => 'nullable|string|max:10',
            'renewal_reminder_days'       => 'nullable|integer|min:1|max:365',
        ]);

        $vendor = $this->service->create(app('current.organization_id'), $data);

        return new VendorResource($vendor);
    }

    public function show(VendorPartner $vendor): VendorResource
    {
        $this->authorize('view', $vendor);
        return new VendorResource($vendor);
    }

    public function update(Request $request, VendorPartner $vendor): VendorResource
    {
        $this->authorize('update', $vendor);

        $data = $request->validate([
            'name'                        => 'sometimes|string|max:255',
            'registration_number'         => 'nullable|string|max:100',
            'tax_id'                      => 'nullable|string|max:50',
            'vendor_type'                 => 'sometimes|in:supplier,service_provider,contractor,consultant,distributor,partner',
            'category'                    => 'nullable|string|max:100',
            'description'                 => 'nullable|string|max:3000',
            'primary_contact_name'        => 'nullable|string|max:255',
            'primary_contact_designation' => 'nullable|string|max:255',
            'contact_email'               => 'nullable|email|max:255',
            'contact_phone'               => 'nullable|string|max:50',
            'website_url'                 => 'nullable|url|max:500',
            'physical_address'            => 'nullable|string|max:500',
            'city'                        => 'nullable|string|max:100',
            'country'                     => 'nullable|string|max:100',
            'notes'                       => 'nullable|string|max:2000',
            'is_active'                   => 'boolean',
            // Partnership & fiscal
            'partnership_level'           => 'nullable|string|max:50',
            'logo_url'                    => 'nullable|url|max:500',
            'fiscal_year_start_month'     => 'nullable|integer|min:1|max:12',
            'renewal_anniversary_date'    => 'nullable|date',
            'renewal_cost'                => 'nullable|numeric|min:0',
            'renewal_cost_currency'       => 'nullable|string|max:10',
            'renewal_reminder_days'       => 'nullable|integer|min:1|max:365',
        ]);

        return new VendorResource($this->service->update($vendor, $data));
    }

    public function destroy(VendorPartner $vendor): JsonResponse
    {
        $this->authorize('delete', $vendor);
        $this->service->delete($vendor);
        return response()->json(null, 204);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Onboarding status transitions
    // ─────────────────────────────────────────────────────────────────────────

    /** Submit a draft vendor for HR review */
    public function submitForReview(VendorPartner $vendor): VendorResource
    {
        $this->authorize('update', $vendor);
        return new VendorResource($this->service->submitForReview($vendor));
    }

    /** HR approves the vendor */
    public function approve(VendorPartner $vendor): VendorResource
    {
        $this->authorize('update', $vendor);
        return new VendorResource($this->service->approve($vendor, auth()->id()));
    }

    /** HR rejects the vendor with a reason */
    public function reject(Request $request, VendorPartner $vendor): VendorResource
    {
        $this->authorize('update', $vendor);
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        return new VendorResource($this->service->reject($vendor, $data['reason']));
    }

    /** HR suspends an active vendor */
    public function suspend(Request $request, VendorPartner $vendor): VendorResource
    {
        $this->authorize('update', $vendor);
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        return new VendorResource($this->service->suspend($vendor, $data['reason']));
    }

    /** Reactivate a rejected or suspended vendor */
    public function reactivate(VendorPartner $vendor): VendorResource
    {
        $this->authorize('update', $vendor);
        return new VendorResource($this->service->reactivate($vendor));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Metrics
    // ─────────────────────────────────────────────────────────────────────────

    public function metrics(): JsonResponse
    {
        $this->authorize('viewAny', VendorPartner::class);
        return response()->json(['data' => $this->service->metrics(app('current.organization_id'))]);
    }
}
