<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkLocationCheckInResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'location_type'    => $this->location_type,
            'customer_name'    => $this->customer_name,
            'notes'            => $this->notes,
            'checked_in_at'    => $this->checked_in_at?->toISOString(),
            'checked_out_at'   => $this->checked_out_at?->toISOString(),
            'duration_minutes' => $this->duration_minutes,
        ];
    }
}
