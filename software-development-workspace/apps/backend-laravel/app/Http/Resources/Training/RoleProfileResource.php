<?php

namespace App\Http\Resources\Training;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'uuid'        => $this->uuid,
            'job_title'   => $this->job_title,
            'description' => $this->description,
            'department'  => $this->whenLoaded('department', fn() => [
                'id'   => $this->department->id,
                'name' => $this->department->name,
            ]),
            'competencies' => CompetencyItemResource::collection($this->whenLoaded('competencies')),
            'created_at'   => $this->created_at?->toDateTimeString(),
        ];
    }
}
