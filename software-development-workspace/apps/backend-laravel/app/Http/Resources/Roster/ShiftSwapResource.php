<?php

namespace App\Http\Resources\Roster;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftSwapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'organization_id'       => $this->organization_id,
            'status'                => $this->status,
            'requester'             => $this->whenLoaded('requester', fn () => [
                'id'       => $this->requester->id,
                'full_name'=> trim("{$this->requester->first_name} {$this->requester->last_name}"),
            ]),
            'requested_employee'    => $this->whenLoaded('requestedEmployee', fn () => [
                'id'       => $this->requestedEmployee->id,
                'full_name'=> trim("{$this->requestedEmployee->first_name} {$this->requestedEmployee->last_name}"),
            ]),
            'requester_assignment'  => $this->whenLoaded('requesterAssignment', fn () => [
                'id'             => $this->requesterAssignment->id,
                'assignment_date'=> $this->requesterAssignment->assignment_date?->toDateString(),
                'shift_name'     => $this->requesterAssignment->shiftType?->name,
            ]),
            'requested_assignment'  => $this->whenLoaded('requestedAssignment', function () {
                if (! $this->requestedAssignment) {
                    return null;
                }

                return [
                    'id'             => $this->requestedAssignment->id,
                    'assignment_date'=> $this->requestedAssignment->assignment_date?->toDateString(),
                    'shift_name'     => $this->requestedAssignment->shiftType?->name,
                ];
            }),
            'requester_notes'       => $this->requester_notes,
            'responder_notes'       => $this->responder_notes,
            'manager_notes'         => $this->manager_notes,
            'approved_by'           => $this->whenLoaded('approvedByEmployee', function () {
                if (! $this->approvedByEmployee) {
                    return null;
                }

                return [
                    'id'       => $this->approvedByEmployee->id,
                    'full_name'=> trim("{$this->approvedByEmployee->first_name} {$this->approvedByEmployee->last_name}"),
                ];
            }),
            'responded_at'          => $this->responded_at?->toISOString(),
            'approved_at'           => $this->approved_at?->toISOString(),
            'created_at'            => $this->created_at?->toISOString(),
        ];
    }
}
