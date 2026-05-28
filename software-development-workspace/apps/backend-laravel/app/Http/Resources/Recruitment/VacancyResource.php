<?php

namespace App\Http\Resources\Recruitment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VacancyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->id,
            'uuid'                    => $this->uuid,
            'name'                    => $this->name,
            'job_title'               => $this->job_title,
            'location'                => $this->location,
            'open_positions'          => $this->open_positions,
            'status'                  => $this->status,
            'description'             => $this->description,
            'closing_date'            => $this->closing_date?->toDateString(),
            'application_form_schema' => $this->application_form_schema ?? [],
            'template_id'             => $this->template_id,
            'workflow'                => new WorkflowResource($this->whenLoaded('workflow')),
            'department'              => $this->whenLoaded('department', fn() => [
                'id' => $this->department->id, 'name' => $this->department->name,
            ]),
            'designation'             => $this->whenLoaded('designation', fn() => [
                'id' => $this->designation->id, 'title' => $this->designation->title,
            ]),
            'hiring_manager'          => $this->whenLoaded('hiringManager', fn() => [
                'id' => $this->hiringManager->id, 'name' => $this->hiringManager->name,
            ]),
            'applications_count'      => $this->whenCounted('applications'),
            'created_at'              => $this->created_at?->toDateTimeString(),
        ];
    }
}
