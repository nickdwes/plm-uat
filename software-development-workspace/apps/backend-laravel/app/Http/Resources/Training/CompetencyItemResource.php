<?php

namespace App\Http\Resources\Training;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompetencyItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'uuid'               => $this->uuid,
            'name'               => $this->name,
            'description'        => $this->description,
            'category'           => $this->category,
            'proficiency_levels' => $this->proficiency_levels ?? [],
            'required_level'     => $this->when(
                isset($this->pivot?->required_level),
                fn() => $this->pivot->required_level
            ),
            'created_at'         => $this->created_at?->toDateTimeString(),
        ];
    }
}
