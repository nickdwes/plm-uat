<?php

namespace App\Http\Resources\Travel;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TravelRequestItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'category'        => $this->whenLoaded('category', fn () => [
                'id' => $this->category?->id, 'name' => $this->category?->name,
            ]),
            'description'     => $this->description,
            'quantity'        => $this->quantity,
            // The rate as resolved at submission, not as it stands today.
            'rate_applied'    => $this->rate_applied,
            'unit'            => $this->unit,
            'amount_claimed'  => $this->amount_claimed,
            'amount_approved' => $this->amount_approved,
            'actual_cost'     => $this->actual_cost,
            // What the allowance was when this line was entered, and whether
            // the estimate went over it — both recorded, not recalculated.
            'policy_limit'    => $this->policy_limit,
            'exceeds_policy'  => $this->exceeds_policy,
            'note'            => $this->note,
        ];
    }
}
