<?php

namespace App\Http\Resources\Vendor;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorPartnershipTierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'uuid'               => $this->uuid,
            'vendor_partner_id'  => $this->vendor_partner_id,
            'tier_name'          => $this->tier_name,
            'tier_order'         => $this->tier_order,
            // What this tier is held on: the partnership generally, or one
            // product line.
            'scope'              => $this->scope ?? 'general',
            'vendor_product_id'  => $this->vendor_product_id,
            'product'            => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->id, 'name' => $this->product->name,
            ] : null),
            'revenue_target'     => $this->revenue_target,
            'revenue_currency'   => $this->revenue_currency,
            // Seats are what make a revenue target checkable.
            'seats_required'     => $this->seats_required,
            'seats_activated'    => $this->seats_activated,
            'tier_requirements'  => $this->whenLoaded('tierRequirements', fn () => $this->tierRequirements
                ->map(fn ($r) => [
                    'id'                 => $r->id,
                    'requirement_type'   => $r->requirement_type,
                    'training_item_id'   => $r->training_item_id,
                    'vendor_professional_certification_id' => $r->vendor_professional_certification_id,
                    'required_headcount' => $r->required_headcount,
                    'label'              => $r->label(),
                    'notes'              => $r->notes,
                ])->values()),
            'min_certifications' => $this->min_certifications,
            'min_headcount'      => $this->min_headcount,
            'requirements'       => $this->requirements,
            'benefits'           => $this->benefits,
            'is_current'         => $this->is_current,
            'valid_from'         => $this->valid_from?->toDateString(),
            'valid_until'        => $this->valid_until?->toDateString(),
            'created_at'         => $this->created_at?->toDateTimeString(),
            'updated_at'         => $this->updated_at?->toDateTimeString(),
        ];
    }
}
