<?php

namespace App\Http\Resources\Vendor;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'uuid'                   => $this->uuid,
            'vendor_partner_id'      => $this->vendor_partner_id,
            'name'                   => $this->name,
            'sku'                    => $this->sku,
            'product_type'           => $this->product_type,
            'technology_space'       => $this->technology_space,
            'technology_subcategory' => $this->technology_subcategory,
            'description'            => $this->description,
            'is_active'              => $this->is_active,
            'created_at'             => $this->created_at?->toDateTimeString(),
            'updated_at'             => $this->updated_at?->toDateTimeString(),
        ];
    }
}
