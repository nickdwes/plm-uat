<?php

namespace App\Http\Resources\Roster;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RosterConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                        => $this->id,
            'organization_id'           => $this->organization_id,
            'primary_detail_shown'      => $this->primary_detail_shown,
            'swap_shifts_enabled'       => $this->swap_shifts_enabled,
            'manager_approval_required' => $this->manager_approval_required,
            'updated_at'                => $this->updated_at?->toISOString(),
        ];
    }
}
