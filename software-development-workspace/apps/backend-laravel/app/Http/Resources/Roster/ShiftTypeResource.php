<?php

namespace App\Http\Resources\Roster;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'organization_id'        => $this->organization_id,
            'name'                   => $this->name,
            'start_time'             => $this->start_time,
            'end_time'               => $this->end_time,
            'break_start'            => $this->break_start,
            'break_duration_minutes' => $this->break_duration_minutes,
            'duration_minutes'       => $this->duration_minutes,
            'net_minutes'            => $this->net_minutes,
            'is_overnight'           => $this->is_overnight,
            'is_active'              => $this->is_active,
            'shift_group'            => $this->whenLoaded('shiftGroup', fn () => [
                'id'   => $this->shiftGroup->id,
                'name' => $this->shiftGroup->name,
            ]),
            'created_at'             => $this->created_at?->toISOString(),
        ];
    }
}
