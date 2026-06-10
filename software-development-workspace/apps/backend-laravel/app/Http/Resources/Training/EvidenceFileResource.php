<?php

namespace App\Http\Resources\Training;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvidenceFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'category'      => $this->category,
            'original_name' => $this->original_name,
            'mime_type'     => $this->mime_type,
            'size_bytes'    => $this->size_bytes,
            'uploaded_by'   => $this->uploaded_by,
            'created_at'    => $this->created_at?->toDateTimeString(),
        ];
    }
}
