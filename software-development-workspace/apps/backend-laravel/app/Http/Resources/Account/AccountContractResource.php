<?php

namespace App\Http\Resources\Account;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'contract_number' => $this->contract_number,
            'type'            => $this->type,
            'contract_value'  => $this->contract_value,
            'start_date'      => $this->start_date?->toDateString(),
            'end_date'        => $this->end_date?->toDateString(),
            'status'          => $this->status,
            'notes'           => $this->notes,

            // Derived here rather than left to the client, so every screen
            // agrees on what "expired" means.
            'is_expired'      => $this->is_expired,
            'days_to_expiry'  => $this->end_date
                ? (int) now()->startOfDay()->diffInDays($this->end_date->startOfDay(), false)
                : null,
        ];
    }
}
