<?php

namespace App\Http\Resources\Training;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrainingItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'uuid'                 => $this->uuid,
            'title'                => $this->title,
            'description'          => $this->description,
            'type'                 => $this->type,
            'delivery'             => $this->delivery,
            'duration_hours'       => $this->duration_hours,
            'duration_minutes'     => $this->duration_minutes,
            'is_long_course'       => $this->is_long_course,
            'provider_name'        => $this->provider_name,
            'provider_url'         => $this->provider_url,
            'cost_currency'        => $this->cost_currency,
            'estimated_cost'       => $this->estimated_cost,
            'tags'                 => $this->tags ?? [],
            'is_active'            => $this->is_active,
            'is_featured'          => $this->is_featured,
            'requires_approval'    => $this->requires_approval,
            'is_budgeted'          => $this->is_budgeted,
            'budget_amount'        => $this->budget_amount,
            'max_enrollments'      => $this->max_enrollments,
            'thumbnail_url'        => $this->thumbnail_url,
            'training_records_count' => $this->whenCounted('trainingRecords'),
            'competencies'         => CompetencyItemResource::collection($this->whenLoaded('competencies')),
            'created_at'           => $this->created_at?->toDateTimeString(),
        ];
    }
}
