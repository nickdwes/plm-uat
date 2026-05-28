<?php

namespace App\Http\Resources\Roster;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'organization_id'=> $this->organization_id,
            'name'           => $this->name,
            'description'    => $this->description,
            'created_at'     => $this->created_at?->toISOString(),
        ];
    }
}
