<?php

namespace App\Http\Resources\Vendor;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorCertificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'uuid'                   => $this->uuid,
            'vendor_partner_id'      => $this->vendor_partner_id,
            'training_item_id'       => $this->training_item_id,
            'training_item'          => $this->whenLoaded('trainingItem', fn() => [
                'id'    => $this->trainingItem->id,
                'title' => $this->trainingItem->title,
                'type'  => $this->trainingItem->type,
            ]),
            'name'                   => $this->name,
            'code'                   => $this->code,
            'level'                  => $this->level,
            'technology_space'       => $this->technology_space,
            'technology_subcategory' => $this->technology_subcategory,
            'description'            => $this->description,
            'validity_months'        => $this->validity_months,
            'exam_cost_usd'          => $this->exam_cost_usd,
            'training_cost_usd'      => $this->training_cost_usd,
            'prerequisites'          => $this->prerequisites,
            'is_active'              => $this->is_active,
            'created_at'             => $this->created_at?->toDateTimeString(),
            'updated_at'             => $this->updated_at?->toDateTimeString(),
        ];
    }
}
