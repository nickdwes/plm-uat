<?php

namespace App\Http\Resources\Training;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrainingAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'training_item_id' => $this->training_item_id,
            'employee_id' => $this->employee_id,
            'assigned_by_user_id' => $this->assigned_by_user_id,
            'manager_id' => $this->manager_id,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'approval_notes' => $this->approval_notes,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'withdrawn_at' => $this->withdrawn_at?->toIso8601String(),
            'due_date' => $this->due_date?->toDateString(),
            'assignment_type' => $this->assignment_type,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Relations
            'training_item' => new TrainingItemResource($this->whenLoaded('trainingItem')),
            'employee' => $this->whenLoaded('employee', function () {
                return [
                    'id' => $this->employee->id,
                    'first_name' => $this->employee->first_name,
                    'last_name' => $this->employee->last_name,
                    'email' => $this->employee->email,
                ];
            }),
            'assigned_by_user' => $this->whenLoaded('assignedByUser', function () {
                return [
                    'id' => $this->assignedByUser->id,
                    'name' => $this->assignedByUser->name,
                    'email' => $this->assignedByUser->email,
                ];
            }),
            'manager' => $this->whenLoaded('manager', function () {
                return [
                    'id' => $this->manager->id,
                    'name' => $this->manager->name,
                    'email' => $this->manager->email,
                ];
            }),
        ];
    }
}
