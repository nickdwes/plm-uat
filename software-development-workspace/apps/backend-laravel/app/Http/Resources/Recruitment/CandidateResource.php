<?php

namespace App\Http\Resources\Recruitment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CandidateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'first_name'   => $this->first_name,
            'middle_name'  => $this->middle_name,
            'last_name'    => $this->last_name,
            'full_name'    => $this->full_name,
            'email'        => $this->email,
            'phone'        => $this->phone,
            'resume_url'   => $this->resume_path ? Storage::url($this->resume_path) : null,
            'social_links' => $this->social_links ?? [],
            'keywords'     => $this->keywords ?? [],
            'source'       => $this->source,
            'notes'        => $this->notes,
            'is_archived'  => (bool) $this->is_archived,
            'archived_at'  => $this->archived_at?->toDateTimeString(),
            'applications' => ApplicationResource::collection($this->whenLoaded('applications')),
            'created_at'   => $this->created_at?->toDateTimeString(),
        ];
    }
}
