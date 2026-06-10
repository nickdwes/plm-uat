<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Resources\Vendor\VendorCertificationResource;
use App\Http\Resources\Vendor\VendorCustomerResource;
use App\Http\Resources\Vendor\VendorIncentiveResource;
use App\Http\Resources\Vendor\VendorPartnershipTierResource;
use App\Http\Resources\Vendor\VendorPortalUserResource;
use App\Http\Resources\Vendor\VendorProductResource;
use App\Http\Resources\Vendor\VendorProjectResource;
use App\Models\Training\VendorPartner;
use App\Models\Vendor\VendorCustomer;
use App\Models\Vendor\VendorCustomerProject;
use App\Models\Vendor\VendorIncentive;
use App\Models\Vendor\VendorPartnershipTier;
use App\Models\Vendor\VendorPortalUser;
use App\Models\Vendor\VendorProduct;
use App\Models\Vendor\VendorProfessionalCertification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Handles Products, Incentives/NFRs, and Professional Certifications
 * for a given Vendor Partner.
 */
class VendorProfileController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // Products & Solutions
    // ─────────────────────────────────────────────────────────────────────────

    public function indexProducts(VendorPartner $vendor): AnonymousResourceCollection
    {
        $this->authorize('view', $vendor);
        return VendorProductResource::collection(
            $vendor->products()->orderBy('technology_space')->orderBy('name')->get()
        );
    }

    public function storeProduct(Request $request, VendorPartner $vendor): VendorProductResource
    {
        $this->authorize('update', $vendor);

        $data = $request->validate([
            'name'                   => 'required|string|max:255',
            'sku'                    => 'nullable|string|max:100',
            'product_type'           => 'required|in:product,solution,service,platform',
            'technology_space'       => 'required|string|max:100',
            'technology_subcategory' => 'nullable|string|max:100',
            'description'            => 'nullable|string|max:3000',
            'is_active'              => 'boolean',
        ]);

        $data['vendor_partner_id'] = $vendor->id;
        return new VendorProductResource(VendorProduct::create($data));
    }

    public function updateProduct(Request $request, VendorPartner $vendor, VendorProduct $product): VendorProductResource
    {
        $this->authorize('update', $vendor);
        abort_if($product->vendor_partner_id !== $vendor->id, 404);

        $data = $request->validate([
            'name'                   => 'sometimes|string|max:255',
            'sku'                    => 'nullable|string|max:100',
            'product_type'           => 'sometimes|in:product,solution,service,platform',
            'technology_space'       => 'sometimes|string|max:100',
            'technology_subcategory' => 'nullable|string|max:100',
            'description'            => 'nullable|string|max:3000',
            'is_active'              => 'boolean',
        ]);

        $product->update($data);
        return new VendorProductResource($product->fresh());
    }

    public function destroyProduct(VendorPartner $vendor, VendorProduct $product): JsonResponse
    {
        $this->authorize('update', $vendor);
        abort_if($product->vendor_partner_id !== $vendor->id, 404);
        $product->delete();
        return response()->json(null, 204);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Incentives & NFRs
    // ─────────────────────────────────────────────────────────────────────────

    public function indexIncentives(VendorPartner $vendor): AnonymousResourceCollection
    {
        $this->authorize('view', $vendor);
        return VendorIncentiveResource::collection(
            $vendor->incentives()->orderBy('incentive_type')->orderBy('name')->get()
        );
    }

    public function storeIncentive(Request $request, VendorPartner $vendor): VendorIncentiveResource
    {
        $this->authorize('update', $vendor);

        $data = $request->validate([
            'name'                  => 'required|string|max:255',
            'incentive_type'        => 'required|in:rebate,nfr,spiff,mdf,deal_registration,co_op,other',
            'description'           => 'nullable|string|max:3000',
            'value'                 => 'nullable|numeric|min:0',
            'value_type'            => 'required|in:percentage,fixed,per_unit,variable',
            'currency'              => 'nullable|string|max:10',
            'eligibility_criteria'  => 'nullable|string|max:3000',
            'start_date'            => 'nullable|date',
            'end_date'              => 'nullable|date|after_or_equal:start_date',
            'is_active'             => 'boolean',
        ]);

        $data['vendor_partner_id'] = $vendor->id;
        return new VendorIncentiveResource(VendorIncentive::create($data));
    }

    public function updateIncentive(Request $request, VendorPartner $vendor, VendorIncentive $incentive): VendorIncentiveResource
    {
        $this->authorize('update', $vendor);
        abort_if($incentive->vendor_partner_id !== $vendor->id, 404);

        $data = $request->validate([
            'name'                  => 'sometimes|string|max:255',
            'incentive_type'        => 'sometimes|in:rebate,nfr,spiff,mdf,deal_registration,co_op,other',
            'description'           => 'nullable|string|max:3000',
            'value'                 => 'nullable|numeric|min:0',
            'value_type'            => 'sometimes|in:percentage,fixed,per_unit,variable',
            'currency'              => 'nullable|string|max:10',
            'eligibility_criteria'  => 'nullable|string|max:3000',
            'start_date'            => 'nullable|date',
            'end_date'              => 'nullable|date|after_or_equal:start_date',
            'is_active'             => 'boolean',
        ]);

        $incentive->update($data);
        return new VendorIncentiveResource($incentive->fresh());
    }

    public function destroyIncentive(VendorPartner $vendor, VendorIncentive $incentive): JsonResponse
    {
        $this->authorize('update', $vendor);
        abort_if($incentive->vendor_partner_id !== $vendor->id, 404);
        $incentive->delete();
        return response()->json(null, 204);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Professional Certifications & Training
    // ─────────────────────────────────────────────────────────────────────────

    public function indexCertifications(VendorPartner $vendor): AnonymousResourceCollection
    {
        $this->authorize('view', $vendor);
        return VendorCertificationResource::collection(
            $vendor->professionalCertifications()->with('trainingItem')->orderBy('technology_space')->orderBy('name')->get()
        );
    }

    public function storeCertification(Request $request, VendorPartner $vendor): VendorCertificationResource
    {
        $this->authorize('update', $vendor);

        $data = $request->validate([
            'name'                   => 'required|string|max:255',
            'code'                   => 'nullable|string|max:50',
            'level'                  => 'required|in:associate,professional,expert,specialist,practitioner,foundation',
            'technology_space'       => 'required|string|max:100',
            'technology_subcategory' => 'nullable|string|max:100',
            'description'            => 'nullable|string|max:3000',
            'validity_months'        => 'nullable|integer|min:1|max:120',
            'exam_cost_usd'          => 'nullable|numeric|min:0',
            'training_cost_usd'      => 'nullable|numeric|min:0',
            'training_item_id'       => 'nullable|integer|exists:training_items,id',
            'prerequisites'          => 'nullable|string|max:2000',
            'is_active'              => 'boolean',
        ]);

        $data['vendor_partner_id'] = $vendor->id;
        return new VendorCertificationResource(VendorProfessionalCertification::create($data));
    }

    public function updateCertification(
        Request $request,
        VendorPartner $vendor,
        VendorProfessionalCertification $certification
    ): VendorCertificationResource {
        $this->authorize('update', $vendor);
        abort_if($certification->vendor_partner_id !== $vendor->id, 404);

        $data = $request->validate([
            'name'                   => 'sometimes|string|max:255',
            'code'                   => 'nullable|string|max:50',
            'level'                  => 'sometimes|in:associate,professional,expert,specialist,practitioner,foundation',
            'technology_space'       => 'sometimes|string|max:100',
            'technology_subcategory' => 'nullable|string|max:100',
            'description'            => 'nullable|string|max:3000',
            'validity_months'        => 'nullable|integer|min:1|max:120',
            'exam_cost_usd'          => 'nullable|numeric|min:0',
            'training_cost_usd'      => 'nullable|numeric|min:0',
            'training_item_id'       => 'nullable|integer|exists:training_items,id',
            'prerequisites'          => 'nullable|string|max:2000',
            'is_active'              => 'boolean',
        ]);

        $certification->update($data);
        return new VendorCertificationResource($certification->fresh()->load('trainingItem'));
    }

    public function destroyCertification(
        VendorPartner $vendor,
        VendorProfessionalCertification $certification
    ): JsonResponse {
        $this->authorize('update', $vendor);
        abort_if($certification->vendor_partner_id !== $vendor->id, 404);
        $certification->delete();
        return response()->json(null, 204);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Partnership Tiers
    // ─────────────────────────────────────────────────────────────────────────

    public function indexTiers(VendorPartner $vendor): AnonymousResourceCollection
    {
        $this->authorize('view', $vendor);
        return VendorPartnershipTierResource::collection(
            $vendor->partnershipTiers()->orderBy('tier_order')->orderBy('tier_name')->get()
        );
    }

    public function storeTier(Request $request, VendorPartner $vendor): VendorPartnershipTierResource
    {
        $this->authorize('update', $vendor);
        $data = $request->validate([
            'tier_name'          => 'required|string|max:100',
            'tier_order'         => 'nullable|integer|min:0',
            'revenue_target'     => 'nullable|numeric|min:0',
            'revenue_currency'   => 'nullable|string|max:10',
            'min_certifications' => 'nullable|integer|min:0',
            'min_headcount'      => 'nullable|integer|min:0',
            'requirements'       => 'nullable|string|max:5000',
            'benefits'           => 'nullable|string|max:5000',
            'is_current'         => 'boolean',
        ]);
        $data['vendor_partner_id'] = $vendor->id;
        // If marking as current, unset others
        if (!empty($data['is_current'])) {
            $vendor->partnershipTiers()->update(['is_current' => false]);
        }
        return new VendorPartnershipTierResource(VendorPartnershipTier::create($data));
    }

    public function updateTier(Request $request, VendorPartner $vendor, VendorPartnershipTier $tier): VendorPartnershipTierResource
    {
        $this->authorize('update', $vendor);
        abort_if($tier->vendor_partner_id !== $vendor->id, 404);
        $data = $request->validate([
            'tier_name'          => 'sometimes|string|max:100',
            'tier_order'         => 'nullable|integer|min:0',
            'revenue_target'     => 'nullable|numeric|min:0',
            'revenue_currency'   => 'nullable|string|max:10',
            'min_certifications' => 'nullable|integer|min:0',
            'min_headcount'      => 'nullable|integer|min:0',
            'requirements'       => 'nullable|string|max:5000',
            'benefits'           => 'nullable|string|max:5000',
            'is_current'         => 'boolean',
        ]);
        if (!empty($data['is_current'])) {
            $vendor->partnershipTiers()->where('id', '!=', $tier->id)->update(['is_current' => false]);
        }
        $tier->update($data);
        return new VendorPartnershipTierResource($tier->fresh());
    }

    public function destroyTier(VendorPartner $vendor, VendorPartnershipTier $tier): JsonResponse
    {
        $this->authorize('update', $vendor);
        abort_if($tier->vendor_partner_id !== $vendor->id, 404);
        $tier->delete();
        return response()->json(null, 204);
    }

    public function setCurrentTier(VendorPartner $vendor, VendorPartnershipTier $tier): VendorPartnershipTierResource
    {
        $this->authorize('update', $vendor);
        abort_if($tier->vendor_partner_id !== $vendor->id, 404);
        $vendor->partnershipTiers()->update(['is_current' => false]);
        $tier->update(['is_current' => true]);
        return new VendorPartnershipTierResource($tier->fresh());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Portal Users
    // ─────────────────────────────────────────────────────────────────────────

    public function indexPortalUsers(VendorPartner $vendor): AnonymousResourceCollection
    {
        $this->authorize('view', $vendor);
        return VendorPortalUserResource::collection(
            $vendor->portalUsers()->orderBy('display_name')->get()
        );
    }

    public function storePortalUser(Request $request, VendorPartner $vendor): VendorPortalUserResource
    {
        $this->authorize('update', $vendor);
        $data = $request->validate([
            'display_name' => 'required|string|max:255',
            'email'        => 'required|email|max:255',
            'role'         => 'nullable|in:admin,user,viewer',
            'portal_url'   => 'nullable|url|max:500',
            'access_level' => 'nullable|string|max:100',
            'is_active'    => 'boolean',
            'user_id'      => 'nullable|exists:users,id',
        ]);
        $data['vendor_partner_id'] = $vendor->id;
        return new VendorPortalUserResource(VendorPortalUser::create($data));
    }

    public function updatePortalUser(Request $request, VendorPartner $vendor, VendorPortalUser $portalUser): VendorPortalUserResource
    {
        $this->authorize('update', $vendor);
        abort_if($portalUser->vendor_partner_id !== $vendor->id, 404);
        $data = $request->validate([
            'display_name' => 'sometimes|string|max:255',
            'email'        => 'sometimes|email|max:255',
            'role'         => 'nullable|in:admin,user,viewer',
            'portal_url'   => 'nullable|url|max:500',
            'access_level' => 'nullable|string|max:100',
            'is_active'    => 'boolean',
            'user_id'      => 'nullable|exists:users,id',
        ]);
        $portalUser->update($data);
        return new VendorPortalUserResource($portalUser->fresh());
    }

    public function destroyPortalUser(VendorPartner $vendor, VendorPortalUser $portalUser): JsonResponse
    {
        $this->authorize('update', $vendor);
        abort_if($portalUser->vendor_partner_id !== $vendor->id, 404);
        $portalUser->delete();
        return response()->json(null, 204);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Customers
    // ─────────────────────────────────────────────────────────────────────────

    public function indexCustomers(VendorPartner $vendor): AnonymousResourceCollection
    {
        $this->authorize('view', $vendor);
        return VendorCustomerResource::collection(
            $vendor->customers()->withCount('projects')->orderBy('name')->get()
        );
    }

    public function storeCustomer(Request $request, VendorPartner $vendor): VendorCustomerResource
    {
        $this->authorize('update', $vendor);
        $data = $request->validate([
            'name'          => 'required|string|max:255',
            'customer_type' => 'nullable|in:enterprise,sme,government,ngo,education,telco,other',
            'industry'      => 'nullable|string|max:100',
            'country'       => 'nullable|string|max:100',
            'contact_name'  => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'notes'         => 'nullable|string|max:2000',
            'is_active'     => 'boolean',
        ]);
        $data['vendor_partner_id'] = $vendor->id;
        $customer = VendorCustomer::create($data);
        return new VendorCustomerResource($customer->loadCount('projects'));
    }

    public function updateCustomer(Request $request, VendorPartner $vendor, VendorCustomer $customer): VendorCustomerResource
    {
        $this->authorize('update', $vendor);
        abort_if($customer->vendor_partner_id !== $vendor->id, 404);
        $data = $request->validate([
            'name'          => 'sometimes|string|max:255',
            'customer_type' => 'nullable|in:enterprise,sme,government,ngo,education,telco,other',
            'industry'      => 'nullable|string|max:100',
            'country'       => 'nullable|string|max:100',
            'contact_name'  => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'notes'         => 'nullable|string|max:2000',
            'is_active'     => 'boolean',
        ]);
        $customer->update($data);
        return new VendorCustomerResource($customer->fresh()->loadCount('projects'));
    }

    public function destroyCustomer(VendorPartner $vendor, VendorCustomer $customer): JsonResponse
    {
        $this->authorize('update', $vendor);
        abort_if($customer->vendor_partner_id !== $vendor->id, 404);
        $customer->delete();
        return response()->json(null, 204);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Customer Projects
    // ─────────────────────────────────────────────────────────────────────────

    public function indexProjects(Request $request, VendorPartner $vendor): AnonymousResourceCollection
    {
        $this->authorize('view', $vendor);
        $query = $vendor->vendorProjects()->with('customer')->orderBy('name');
        if ($request->filled('customer_id')) {
            $query->where('vendor_customer_id', $request->input('customer_id'));
        }
        return VendorProjectResource::collection($query->get());
    }

    public function storeProject(Request $request, VendorPartner $vendor): VendorProjectResource
    {
        $this->authorize('update', $vendor);
        $data = $request->validate([
            'name'                   => 'required|string|max:255',
            'vendor_customer_id'     => 'nullable|exists:vendor_customers,id',
            'project_code'           => 'nullable|string|max:100',
            'status'                 => 'nullable|in:active,completed,on_hold,cancelled,pipeline',
            'technology_space'       => 'nullable|string|max:100',
            'technology_subcategory' => 'nullable|string|max:100',
            'description'            => 'nullable|string|max:3000',
            'start_date'             => 'nullable|date',
            'end_date'               => 'nullable|date|after_or_equal:start_date',
            'contract_value'         => 'nullable|numeric|min:0',
            'currency'               => 'nullable|string|max:10',
            'notes'                  => 'nullable|string|max:2000',
        ]);
        $data['vendor_partner_id'] = $vendor->id;
        $project = VendorCustomerProject::create($data);
        return new VendorProjectResource($project->load('customer'));
    }

    public function updateProject(Request $request, VendorPartner $vendor, VendorCustomerProject $project): VendorProjectResource
    {
        $this->authorize('update', $vendor);
        abort_if($project->vendor_partner_id !== $vendor->id, 404);
        $data = $request->validate([
            'name'                   => 'sometimes|string|max:255',
            'vendor_customer_id'     => 'nullable|exists:vendor_customers,id',
            'project_code'           => 'nullable|string|max:100',
            'status'                 => 'nullable|in:active,completed,on_hold,cancelled,pipeline',
            'technology_space'       => 'nullable|string|max:100',
            'technology_subcategory' => 'nullable|string|max:100',
            'description'            => 'nullable|string|max:3000',
            'start_date'             => 'nullable|date',
            'end_date'               => 'nullable|date',
            'contract_value'         => 'nullable|numeric|min:0',
            'currency'               => 'nullable|string|max:10',
            'notes'                  => 'nullable|string|max:2000',
        ]);
        $project->update($data);
        return new VendorProjectResource($project->fresh()->load('customer'));
    }

    public function destroyProject(VendorPartner $vendor, VendorCustomerProject $project): JsonResponse
    {
        $this->authorize('update', $vendor);
        abort_if($project->vendor_partner_id !== $vendor->id, 404);
        $project->delete();
        return response()->json(null, 204);
    }
}
