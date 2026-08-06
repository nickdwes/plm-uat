<?php

namespace App\Http\Resources\Account;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'category'        => $this->category,
            'title'           => $this->title,
            'first_name'      => $this->first_name,
            'last_name'       => $this->last_name,
            'full_name'       => $this->full_name,
            'designation'     => $this->designation,
            'department'      => $this->department,
            'email'           => $this->email,
            'phone'           => $this->phone,
            'alternate_phone' => $this->alternate_phone,
            'is_primary'      => $this->is_primary,
            'is_active'       => $this->is_active,
        ];
    }
}
