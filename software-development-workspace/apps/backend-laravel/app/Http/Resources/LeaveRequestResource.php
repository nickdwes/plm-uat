<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            // What people quote at each other. Derived from the id rather than
            // stored, so it needs no generator and no backfill.
            'reference'        => $this->reference,
            'employee_id'      => $this->employee_id,
            'organization_id'  => $this->organization_id,
            'employee'         => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id'              => $this->employee->id,
                'first_name'      => $this->employee->first_name,
                'last_name'       => $this->employee->last_name,
                'employee_number' => $this->employee->employee_number,
            ] : null),
            'leave_type'       => $this->whenLoaded('leaveType', fn () => [
                'id'   => $this->leaveType->id,
                'name' => $this->leaveType->name,
                'code' => $this->leaveType->code,
            ]),
            'from_date'        => $this->from_date?->toDateString(),
            'to_date'          => $this->to_date?->toDateString(),
            'days_requested'   => (float) $this->days_requested,
            'is_half_day'      => (bool) $this->is_half_day,
            'half_day_period'  => $this->half_day_period,
            'scattered_dates'  => $this->scattered_dates,
            'reason'           => $this->reason,
            'status'           => $this->status,
            'rejection_reason' => $this->when(
                $this->status === 'rejected',
                $this->rejection_reason,
            ),
            'approved_by'      => $this->whenLoaded('approvedBy', fn () => [
                'id'        => $this->approvedBy->id,
                'full_name' => $this->approvedBy->full_name,
            ]),
            'actioned_at'      => $this->actioned_at?->toISOString(),
            'created_at'       => $this->created_at?->toISOString(),
            // Counted rather than listed: the table shows a paperclip, and the
            // drawer asks for the files themselves when it opens.
            'attachments_count' => $this->whenCounted('attachments'),
            'messages_count'    => $this->whenCounted('messages'),
        ];
    }
}
