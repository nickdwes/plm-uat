<?php

namespace App\Http\Resources\Roster;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RosterAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'schedule_id'     => $this->schedule_id,
            'employee'        => $this->whenLoaded('employee', fn () => [
                'id'          => $this->employee->id,
                'full_name'   => $this->employee->full_name ?? trim("{$this->employee->first_name} {$this->employee->last_name}"),
                'employee_number' => $this->employee->employee_number,
            ]),
            'shift_type'      => $this->whenLoaded('shiftType', function () {
                if (! $this->shiftType) {
                    return null;
                }

                return [
                    'id'               => $this->shiftType->id,
                    'name'             => $this->shiftType->name,
                    'start_time'       => $this->shiftType->start_time,
                    'end_time'         => $this->shiftType->end_time,
                    'duration_minutes' => $this->shiftType->duration_minutes,
                    'is_overnight'     => $this->shiftType->is_overnight,
                ];
            }),
            'assignment_date' => $this->assignment_date?->toDateString(),
            'type'            => $this->type,
            'notes'           => $this->notes,
            'created_at'      => $this->created_at?->toISOString(),
        ];
    }
}
