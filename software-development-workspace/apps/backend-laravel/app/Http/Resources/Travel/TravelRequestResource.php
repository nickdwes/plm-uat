<?php

namespace App\Http\Resources\Travel;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TravelRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'reference' => $this->reference,
            'status'    => $this->status,

            'employee'  => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee?->id, 'name' => $this->employee?->full_name,
            ]),
            'account'     => $this->whenLoaded('account', fn () => [
                'id' => $this->account?->id, 'name' => $this->account?->name,
            ]),
            'destination' => $this->whenLoaded('destination', fn () => [
                'id' => $this->destination?->id, 'name' => $this->destination?->name,
            ]),

            'travel_mode'    => $this->travel_mode,
            'departure_date' => $this->departure_date?->toDateString(),
            'return_date'    => $this->return_date?->toDateString(),
            'days'           => $this->days,
            'purpose'         => $this->purpose,
            'business_impact' => $this->business_impact,

            'currency'         => $this->currency,
            'total_claimed'    => $this->total_claimed,
            'total_approved'   => $this->total_approved,
            'advance_required' => $this->advance_required,

            'priority'             => $this->priority,
            'accommodation_needed' => $this->accommodation_needed,
            'flexible_days'        => $this->flexible_days,
            'advance_justification'=> $this->advance_justification,
            'linked_task_ref'      => $this->linked_task_ref,

            'items'     => TravelRequestItemResource::collection($this->whenLoaded('items')),
            'itinerary' => $this->whenLoaded('itinerary'),
            'approvals' => TravelApprovalResource::collection($this->whenLoaded('approvals')),

            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
