<?php

namespace App\Http\Resources\Vendor;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorCustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'uuid'              => $this->uuid,
            'vendor_partner_id' => $this->vendor_partner_id,
            'name'              => $this->name,
            'customer_type'     => $this->customer_type,
            'industry'          => $this->industry,
            'country'           => $this->country,
            'contact_name'      => $this->contact_name,
            'contact_email'     => $this->contact_email,
            'contact_phone'     => $this->contact_phone,
            'notes'             => $this->notes,
            'is_active'         => $this->is_active,
            'projects_count'    => $this->whenCounted('projects'),
            'created_at'        => $this->created_at?->toDateTimeString(),
            'updated_at'        => $this->updated_at?->toDateTimeString(),
        ];
    }
}
