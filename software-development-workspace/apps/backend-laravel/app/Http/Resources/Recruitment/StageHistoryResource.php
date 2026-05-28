<?php

namespace App\Http\Resources\Recruitment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StageHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'from_stage'   => $this->whenLoaded('fromStage', fn() => [
                'id' => $this->fromStage?->id, 'name' => $this->fromStage?->name,
            ]),
            'to_stage'     => $this->whenLoaded('toStage', fn() => [
                'id' => $this->toStage->id, 'name' => $this->toStage->name, 'stage_type' => $this->toStage->stage_type,
            ]),
            'moved_by'     => $this->whenLoaded('movedBy', fn() => [
                'id' => $this->movedBy->id, 'name' => $this->movedBy->name,
            ]),
            'notes'        => $this->notes,
            'moved_at'     => $this->moved_at?->toDateTimeString(),
        ];
    }
}
