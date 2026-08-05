<?php

namespace App\Http\Resources\Account;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'status'            => $this->status,
            'country'           => $this->country,
            'physical_location' => $this->physical_location,

            'account_manager'   => $this->whenLoaded('accountManager', fn () => [
                'id'   => $this->accountManager?->id,
                'name' => $this->accountManager?->full_name,
            ]),
            'type'              => $this->whenLoaded('type', fn () => ['id' => $this->type?->id, 'name' => $this->type?->name]),
            'sector'            => $this->whenLoaded('sector', fn () => ['id' => $this->sector?->id, 'name' => $this->sector?->name]),
            'subsector'         => $this->whenLoaded('subsector', fn () => ['id' => $this->subsector?->id, 'name' => $this->subsector?->name]),

            'number_of_branches'  => $this->number_of_branches,
            'number_of_employees' => $this->number_of_employees,
            'fund_source'         => $this->fund_source,
            'vat_registered'      => $this->vat_registered,
            'tax_pin'             => $this->tax_pin,
            'details'             => $this->details,

            'contacts'   => AccountContactResource::collection($this->whenLoaded('contacts')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
