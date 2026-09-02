<?php

namespace App\Http\Resources\Training;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrainingBudgetRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'uuid'                 => $this->uuid,
            'mode'                 => $this->mode,
            'training_item'        => $this->whenLoaded('trainingItem', fn () => $this->trainingItem ? [
                'id' => $this->trainingItem->id, 'title' => $this->trainingItem->title,
            ] : null),
            'employee'             => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id, 'name' => $this->employee->full_name,
            ] : null),
            'payment_instructions' => $this->payment_instructions,
            'total_amount'         => $this->total_amount,
            'currency'             => $this->currency,
            'status'               => $this->status,
            'manager'              => $this->whenLoaded('manager', fn () => $this->manager ? [
                'id' => $this->manager->id, 'name' => $this->manager->name,
            ] : null),
            'manager_decided_at'   => $this->manager_decided_at?->toDateTimeString(),
            'manager_notes'        => $this->manager_notes,
            'finance_decided_by'   => $this->whenLoaded('financeDecidedBy', fn () => $this->financeDecidedBy ? [
                'id' => $this->financeDecidedBy->id, 'name' => $this->financeDecidedBy->name,
            ] : null),
            'finance_decided_at'   => $this->finance_decided_at?->toDateTimeString(),
            'finance_notes'        => $this->finance_notes,
            'rejected_stage'       => $this->rejected_stage,
            'rejection_reason'     => $this->rejection_reason,
            'submitted_by'         => $this->whenLoaded('submittedBy', fn () => $this->submittedBy ? [
                'id' => $this->submittedBy->id, 'name' => $this->submittedBy->name,
            ] : null),
            'items'                => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id'              => $item->id,
                'employee'        => ['id' => $item->employee->id, 'name' => $item->employee->full_name],
                'training_item'   => ['id' => $item->trainingItem->id, 'title' => $item->trainingItem->title],
                'cost'            => $item->cost,
                'achieve_by_date' => $item->achieve_by_date?->toDateString(),
            ])),
            'created_at'           => $this->created_at?->toDateTimeString(),
        ];
    }
}
