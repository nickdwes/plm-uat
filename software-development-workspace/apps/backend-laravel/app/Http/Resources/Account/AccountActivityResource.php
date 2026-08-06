<?php

namespace App\Http\Resources\Account;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'kind'        => $this->kind,
            'type'        => $this->type,
            'body'        => $this->body,
            'occurred_at' => $this->occurred_at?->toIso8601String(),

            // Named rather than left as an id: the feed reads as a sentence
            // about a person, and a numeric author makes it unreadable.
            'actor'       => [
                'id'   => $this->employee?->id,
                'name' => $this->employee?->full_name ?? $this->author?->name,
            ],
        ];
    }
}
