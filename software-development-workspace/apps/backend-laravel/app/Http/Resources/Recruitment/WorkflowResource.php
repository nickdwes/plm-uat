<?php

namespace App\Http\Resources\Recruitment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'description' => $this->description,
            'is_default'  => $this->is_default,
            'stages'      => WorkflowStageResource::collection($this->whenLoaded('stages')),
            'created_at'  => $this->created_at?->toDateTimeString(),
        ];
    }
}
