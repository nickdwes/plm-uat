<?php

namespace App\Http\Resources\Vendor;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'uuid'                   => $this->uuid,
            'vendor_partner_id'      => $this->vendor_partner_id,
            'vendor_customer_id'     => $this->vendor_customer_id,
            'customer_name'          => $this->customer?->name,
            'name'                   => $this->name,
            'project_code'           => $this->project_code,
            'status'                 => $this->status,
            'technology_space'       => $this->technology_space,
            'technology_subcategory' => $this->technology_subcategory,
            'description'            => $this->description,
            'start_date'             => $this->start_date?->toDateString(),
            'end_date'               => $this->end_date?->toDateString(),
            'contract_value'         => $this->contract_value,
            'currency'               => $this->currency,
            'notes'                  => $this->notes,
            'created_at'             => $this->created_at?->toDateTimeString(),
            'updated_at'             => $this->updated_at?->toDateTimeString(),
        ];
    }
}
