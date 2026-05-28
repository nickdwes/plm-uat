<?php

namespace App\Http\Resources\Recruitment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VacancyTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->id,
            'name'                    => $this->name,
            'job_title'               => $this->job_title,
            'location'                => $this->location,
            'default_positions'       => $this->default_positions,
            'is_active'               => $this->is_active,
            'application_form_schema' => $this->application_form_schema ?? [],
            'screening_rules'         => $this->screening_rules ?? [],
            'workflow'                => new WorkflowResource($this->whenLoaded('workflow')),
            'department'              => $this->whenLoaded('department', fn() => [
                'id' => $this->department->id, 'name' => $this->department->name,
            ]),
            'designation'             => $this->whenLoaded('designation', fn() => [
                'id' => $this->designation->id, 'title' => $this->designation->title,
            ]),
            'created_at'              => $this->created_at?->toDateTimeString(),
        ];
    }
}
