<?php

namespace App\Http\Resources\Training;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrainingRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'uuid'             => $this->uuid,
            'assignment_type'  => $this->assignment_type,
            'status'           => $this->status,
            'progress_percent' => $this->progress_percent,
            'started_at'       => $this->started_at?->toDateTimeString(),
            'completed_at'     => $this->completed_at?->toDateTimeString(),
            'due_date'         => $this->due_date?->toDateString(),
            'score'                    => $this->score,
            'certificate_number'       => $this->certificate_number,
            'certificate_expiry_date'  => $this->certificate_expiry_date?->toDateString(),
            'is_priority'      => $this->is_priority,
            'priority_reason'  => $this->priority_reason,
            'training_item'    => new TrainingItemResource($this->whenLoaded('trainingItem')),
            'employee'         => $this->whenLoaded('employee', fn() => [
                'id'    => $this->employee->id,
                'name'  => $this->employee->full_name ?? ($this->employee->first_name . ' ' . $this->employee->last_name),
                'email' => $this->employee->email ?? null,
            ]),
            'evidence_files'    => EvidenceFileResource::collection($this->whenLoaded('evidenceFiles')),
            'progress_updates'  => $this->whenLoaded('progressUpdates', fn() =>
                $this->progressUpdates->map(fn($u) => [
                    'id'               => $u->id,
                    'progress_percent' => $u->progress_percent,
                    'notes'            => $u->notes,
                    'recorded_at'      => $u->recorded_at?->toDateTimeString(),
                ])
            ),
            'created_at'        => $this->created_at?->toDateTimeString(),
        ];
    }
}
