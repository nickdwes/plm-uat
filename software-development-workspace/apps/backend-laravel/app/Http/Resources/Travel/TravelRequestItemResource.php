<?php

namespace App\Http\Resources\Travel;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TravelRequestItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'category'        => $this->whenLoaded('category', fn () => [
                'id' => $this->category?->id, 'name' => $this->category?->name,
            ]),
            'description'     => $this->description,
            'quantity'        => $this->quantity,
            // The rate as resolved at submission, not as it stands today.
            'rate_applied'    => $this->rate_applied,
            'unit'            => $this->unit,
            'amount_claimed'  => $this->amount_claimed,
            'amount_approved' => $this->amount_approved,
            'actual_cost'     => $this->actual_cost,
            // What the allowance was when this line was entered, and whether
            // the estimate went over it — both recorded, not recalculated.
            'policy_limit'    => $this->policy_limit,
            'exceeds_policy'  => $this->exceeds_policy,

            // What closing this line needs, and whose job it is — so the UI
            // can ask the right person rather than showing everyone an upload
            // box they may not be able to use.
            'evidence_type'     => $this->category?->evidence_type ?? 'none',
            'evidence_provider' => $this->category?->evidence_provider ?? 'requestor',
            'awaiting_evidence' => $this->awaitsEvidence(),
            'evidence'          => $this->whenLoaded('evidence', fn () => $this->evidence->map(fn ($e) => [
                'id'            => $e->id,
                'kind'          => $e->kind,
                'explanation'   => $e->explanation,
                'original_name' => $e->original_name,
                'amount'        => $e->amount,
                'submitted_by'  => $e->submittedBy?->full_name,
                'submitted_at'  => $e->submitted_at?->toIso8601String(),
                'has_file'      => $e->storage_path !== null,
            ])),
            'note'            => $this->note,
        ];
    }
}
