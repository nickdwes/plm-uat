<?php

namespace App\Http\Resources\Pipeline;

use App\Support\Pipeline\LeadQualification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'reference' => $this->reference,
            'name'      => $this->name,
            'summary'   => $this->summary,
            'source'    => $this->source,
            'status'    => $this->status,
            'priority'  => $this->priority,
            'deadline'  => $this->deadline?->toDateString(),
            'website'   => $this->website,

            'problem_statement' => $this->problem_statement,
            'proposed_solution' => $this->proposed_solution,

            'account' => $this->whenLoaded('account', fn () => $this->account ? [
                'id'   => $this->account->id,
                'name' => $this->account->name,
                'initials' => $this->account->initials,
            ] : null),

            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'id' => $this->owner->id, 'name' => $this->owner->full_name,
            ] : null),

            'team' => $this->whenLoaded('team', fn () => $this->team->map(fn ($m) => [
                'id'       => $m->id,
                'employee' => ['id' => $m->employee?->id, 'name' => $m->employee?->full_name],
                'role'     => $m->role,
            ])),

            'stakeholders' => $this->whenLoaded('stakeholders', fn () => $this->stakeholders->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'role' => $s->role,
                'email' => $s->email, 'phone' => $s->phone, 'influence' => $s->influence,
            ])),

            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => [
                'id' => $a->id, 'label' => $a->label, 'document_type' => $a->document_type,
                'original_name' => $a->original_name, 'size_bytes' => $a->size_bytes,
                'uploaded_at' => $a->created_at?->toIso8601String(),
            ])),

            /*
             * Five graded dimensions rather than one figure. A lead at 89
             * tells you nothing to do; 94 on timeline and 60 on solution match
             * tells you to go and fix the solution.
             */
            'qualification' => [
                'dimensions' => collect(LeadQualification::DIMENSIONS)
                    ->map(fn ($label, $key) => [
                        'key' => $key, 'label' => $label, 'value' => $this->{$key},
                    ])->values(),

                'score'        => $this->qualification_score,
                'band'         => $this->qualification_band,
                // 90 from one dimension and 90 from five are not the same
                // claim, and only one is worth acting on.
                'completeness' => LeadQualification::completeness($this->resource),
                'percentile'   => LeadQualification::percentile($this->resource),

                'summary' => [
                    'budget_availability'       => $this->budget_availability,
                    'stakeholder_engagement'    => $this->stakeholder_engagement,
                    'timeline_estimate'         => $this->timeline_estimate,
                    'technical_readiness'       => $this->technical_readiness,
                    'decision_authority_access' => $this->decision_authority_access,
                ],
                'notes' => $this->qualification_notes,
            ],

            /*
             * What it became. Present rather than encoded in the status, which
             * is the whole reason the legacy's status could not be counted on.
             */
            'opportunity' => $this->whenLoaded('opportunity', fn () => $this->opportunity ? [
                'id' => $this->opportunity->id, 'reference' => $this->opportunity->reference,
                'name' => $this->opportunity->name,
            ] : null),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
