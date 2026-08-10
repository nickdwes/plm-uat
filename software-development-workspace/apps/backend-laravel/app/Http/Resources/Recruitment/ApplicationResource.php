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

        /*
         * A route, not a storage URL.
         *
         * This looked the attachment up on the `public` disk and handed back
         * its symlinked address. That disk is unauthenticated — it is where the
         * CVs were leaking from — and nothing has been written there since they
         * moved. So the lookup missed every time and the client was told there
         * was no attachment, while the file sat encrypted on the private disk.
         *
         * The path itself is deliberately not exposed: it is a storage key, and
         * the client has no use for one it cannot fetch.
         */
        $data['attachment_url'] = ! empty($data['attachment_path'])
            ? "/api/v1/applications/{$this->id}/attachment"
            : null;

        unset($data['attachment_path']);

        return $data;
    }
}
