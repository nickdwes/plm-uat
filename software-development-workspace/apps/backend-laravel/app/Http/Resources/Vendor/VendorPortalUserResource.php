<?php

namespace App\Http\Resources\Vendor;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorPortalUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'uuid'              => $this->uuid,
            'vendor_partner_id' => $this->vendor_partner_id,
            'user_id'           => $this->user_id,
            'display_name'      => $this->display_name,
            'email'             => $this->email,
            'role'              => $this->role,
            'portal_url'        => $this->portal_url,
            'access_level'      => $this->access_level,
            'is_active'         => $this->is_active,
            'created_at'        => $this->created_at?->toDateTimeString(),
            'updated_at'        => $this->updated_at?->toDateTimeString(),
        ];
    }
}
