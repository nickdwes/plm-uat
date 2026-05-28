<?php

namespace App\Http\Resources\Roster;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RosterScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'organization_id' => $this->organization_id,
            'name'         => $this->name,
            'start_date'   => $this->start_date?->toDateString(),
            'end_date'     => $this->end_date?->toDateString(),
            'status'       => $this->status,
            'published_at' => $this->published_at?->toISOString(),
            'created_by'   => $this->whenLoaded('creator', fn () => [
                'id'   => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'created_at'   => $this->created_at?->toISOString(),
        ];
    }
}
