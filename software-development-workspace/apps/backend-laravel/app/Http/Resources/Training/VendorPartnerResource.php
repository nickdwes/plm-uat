<?php

namespace App\Http\Resources\Training;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorPartnerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'uuid'          => $this->uuid,
            'name'          => $this->name,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'website_url'   => $this->website_url,
            'notes'         => $this->notes,
            'is_active'     => $this->is_active,
            'requirements'  => $this->whenLoaded('requiredCertifications', function () {
                return $this->requiredCertifications->map(fn($item) => [
                    'training_item_id'   => $item->id,
                    'training_item_uuid' => $item->uuid,
                    'title'              => $item->title,
                    'type'               => $item->type,
                    'required_headcount' => (int) $item->pivot->required_headcount,
                    'notes'              => $item->pivot->notes,
                ]);
            }),
            'created_at'    => $this->created_at?->toDateTimeString(),
            'updated_at'    => $this->updated_at?->toDateTimeString(),
        ];
    }
}
