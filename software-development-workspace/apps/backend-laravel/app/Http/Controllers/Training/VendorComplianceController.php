<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Http\Resources\Training\VendorPartnerResource;
use App\Models\Training\VendorPartner;
use App\Services\Training\VendorComplianceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class VendorComplianceController extends Controller
{
    public function __construct(private readonly VendorComplianceService $service) {}

    // ── Vendor CRUD ───────────────────────────────────────────────────────────

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', VendorPartner::class);

        $vendors = $this->service->listVendors(
            organizationId: app('current.organization_id'),
            filters: $request->only(['search', 'is_active']),
            perPage: (int) $request->input('per_page', 50),
        );

        return VendorPartnerResource::collection($vendors);
    }

    public function store(Request $request): VendorPartnerResource
    {
        $this->authorize('create', VendorPartner::class);

        $data = $request->validate([
            'name'          => 'required|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'website_url'   => 'nullable|url|max:500',
            'notes'         => 'nullable|string|max:2000',
            'is_active'     => 'boolean',
        ]);

        $vendor = $this->service->createVendor(app('current.organization_id'), $data);

        return new VendorPartnerResource($vendor->load('requiredCertifications'));
    }

    public function show(VendorPartner $vendorPartner): VendorPartnerResource
    {
        $this->authorize('view', $vendorPartner);
        return new VendorPartnerResource($vendorPartner->load('requiredCertifications'));
    }

    public function update(Request $request, VendorPartner $vendorPartner): VendorPartnerResource
    {
        $this->authorize('update', $vendorPartner);

        $data = $request->validate([
            'name'          => 'sometimes|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'website_url'   => 'nullable|url|max:500',
            'notes'         => 'nullable|string|max:2000',
            'is_active'     => 'boolean',
        ]);

        $vendor = $this->service->updateVendor($vendorPartner, $data);

        return new VendorPartnerResource($vendor->load('requiredCertifications'));
    }

    public function destroy(VendorPartner $vendorPartner): JsonResponse
    {
        $this->authorize('delete', $vendorPartner);
        $this->service->destroyVendor($vendorPartner);
        return response()->json(null, 204);
    }

    // ── Requirements ──────────────────────────────────────────────────────────

    /**
     * Sync certification requirements for a vendor.
     * Body: { requirements: [{training_item_id, required_headcount, notes?}] }
     */
    public function syncRequirements(Request $request, VendorPartner $vendorPartner): VendorPartnerResource
    {
        $this->authorize('update', $vendorPartner);

        $data = $request->validate([
            'requirements'                          => 'present|array',
            'requirements.*.training_item_id'       => 'required|integer|exists:training_items,id',
            'requirements.*.required_headcount'     => 'required|integer|min:1|max:9999',
            'requirements.*.notes'                  => 'nullable|string|max:500',
        ]);

        $this->service->syncRequirements($vendorPartner, $data['requirements']);

        return new VendorPartnerResource($vendorPartner->load('requiredCertifications'));
    }

    // ── Compliance ────────────────────────────────────────────────────────────

    /**
     * Full compliance summary for all vendors in the organisation.
     */
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', VendorPartner::class);

        $data = $this->service->summary(app('current.organization_id'));

        return response()->json(['data' => $data]);
    }

    /**
     * Compliance status for a single vendor.
     */
    public function vendorStatus(VendorPartner $vendorPartner): JsonResponse
    {
        $this->authorize('view', $vendorPartner);

        $status = $this->service->status($vendorPartner->load('requiredCertifications'));

        return response()->json(['data' => $status]);
    }

    // ── Bulk assign ───────────────────────────────────────────────────────────

    /**
     * Bulk-assign priority-flagged training records for all eligible employees
     * who don't yet have a record for the specified training item.
     *
     * Body: { training_item_id: N }
     */
    public function bulkAssign(Request $request): JsonResponse
    {
        $this->authorize('create', VendorPartner::class);

        $data = $request->validate([
            'training_item_id' => 'required|integer|exists:training_items,id',
        ]);

        $count = $this->service->bulkAssignForGap(
            organizationId: app('current.organization_id'),
            trainingItemId: $data['training_item_id'],
        );

        return response()->json([
            'message' => "Created {$count} priority training record(s).",
            'count'   => $count,
        ]);
    }
}
