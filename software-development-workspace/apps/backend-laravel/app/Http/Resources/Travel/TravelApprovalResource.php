<?php

namespace App\Http\Resources\Travel;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TravelApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'stage'    => $this->stage,
            'sequence' => $this->sequence,
            'status'   => $this->status,
            'expected_approver' => $this->whenLoaded('expectedApprover', fn () => [
                'id' => $this->expectedApprover?->id, 'name' => $this->expectedApprover?->full_name,
            ]),
            'comment'    => $this->comment,
            'decided_at' => $this->decided_at?->toIso8601String(),
        ];
    }
}
