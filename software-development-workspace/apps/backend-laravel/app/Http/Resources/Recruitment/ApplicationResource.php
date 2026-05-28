<?php

namespace App\Http\Resources\Recruitment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'status'           => $this->status,
            'screening_score'  => $this->screening_score,
            'form_data'        => $this->buildFormData(),
            'applied_at'       => $this->applied_at?->toDateTimeString(),
            'candidate'        => new CandidateResource($this->whenLoaded('candidate')),
            'current_stage'    => new WorkflowStageResource($this->whenLoaded('currentStage')),
            'stage_history'    => StageHistoryResource::collection($this->whenLoaded('stageHistory')),
            'vacancy_id'       => $this->vacancy_id,
            'vacancy'          => $this->whenLoaded('vacancy', fn() => [
                'id' => $this->vacancy->id, 'name' => $this->vacancy->name,
            ]),
            'created_at'       => $this->created_at?->toDateTimeString(),
        ];
    }

    private function buildFormData(): array
    {
        $data = $this->form_data ?? [];
        if (!empty($data['attachment_path'])) {
            $data['attachment_url'] = Storage::disk('public')->exists($data['attachment_path'])
                ? Storage::disk('public')->url($data['attachment_path'])
                : null;
        } else {
            $data['attachment_url'] = null;
        }
        return $data;
    }
}
