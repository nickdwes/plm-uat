<?php

namespace App\Http\Resources\Vendor;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorIncentiveResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'uuid'                  => $this->uuid,
            'vendor_partner_id'     => $this->vendor_partner_id,
            'name'                  => $this->name,
            'incentive_type'        => $this->incentive_type,
            'description'           => $this->description,
            'value'                 => $this->value,
            'value_type'            => $this->value_type,
            'currency'              => $this->currency,
            'eligibility_criteria'  => $this->eligibility_criteria,
            'start_date'            => $this->start_date?->toDateString(),
            'end_date'              => $this->end_date?->toDateString(),
            'is_active'             => $this->is_active,
            'created_at'            => $this->created_at?->toDateTimeString(),
            'updated_at'            => $this->updated_at?->toDateTimeString(),
        ];
    }
}
