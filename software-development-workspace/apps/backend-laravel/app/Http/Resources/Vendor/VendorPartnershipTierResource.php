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
            'revenue_target'     => $this->revenue_target,
            'revenue_currency'   => $this->revenue_currency,
            'min_certifications' => $this->min_certifications,
            'min_headcount'      => $this->min_headcount,
            'requirements'       => $this->requirements,
            'benefits'           => $this->benefits,
            'is_current'         => $this->is_current,
            'created_at'         => $this->created_at?->toDateTimeString(),
            'updated_at'         => $this->updated_at?->toDateTimeString(),
        ];
    }
}
