<?php

namespace App\Http\Resources\Account;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'account_contract_id' => $this->account_contract_id,
            'document_type'       => $this->document_type,
            'label'               => $this->label ?? $this->original_name,
            'original_name'       => $this->original_name,
            'mime_type'           => $this->mime_type,
            'size_bytes'          => $this->size_bytes,
            'expiry_date'         => $this->expiry_date?->toDateString(),

            /*
             * No URL. These are served through an authorised download route,
             * because a signed contract carries commercial terms and
             * signatures — the same reason candidate CVs stopped being
             * publicly addressable.
             */
            'uploaded_at'         => $this->created_at?->toIso8601String(),
        ];
    }
}
