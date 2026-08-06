<?php

namespace App\Http\Resources\Pipeline;

use App\Support\Pipeline\OpportunityScore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OpportunityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'reference'   => $this->reference,
            'name'        => $this->name,
            'description' => $this->description,
            'type'        => $this->opportunity_type,
            'practice'    => $this->practice,
            'amount'      => $this->amount,
            'expected_close_date' => $this->expected_close_date?->toDateString(),

            'phase' => $this->whenLoaded('phase', fn () => $this->phase ? [
                'id' => $this->phase->id, 'name' => $this->phase->name,
                'position' => $this->phase->position, 'outcome' => $this->phase->outcome,
            ] : null),

            /*
             * Read from the phase, never stored. The legacy kept a status
             * beside the phase and the two disagreed on every row.
             */
            'outcome'   => $this->outcome,
            'is_open'   => $this->is_open,
            'closed_at' => $this->closed_at?->toIso8601String(),

            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'id' => $this->owner->id, 'name' => $this->owner->full_name,
            ] : null),

            'presale_lead' => $this->whenLoaded('presaleLead', fn () => $this->presaleLead ? [
                'id' => $this->presaleLead->id, 'name' => $this->presaleLead->full_name,
            ] : null),

            'assignees' => $this->whenLoaded('assignees', fn () => $this->assignees->map(fn ($a) => [
                'id'       => $a->id,
                'employee' => ['id' => $a->employee?->id, 'name' => $a->employee?->full_name],
                'role'     => $a->role,
            ])),

            'account' => $this->whenLoaded('account', fn () => $this->account ? [
                'id' => $this->account->id, 'name' => $this->account->name,
            ] : null),

            'lead' => $this->whenLoaded('lead', fn () => $this->lead ? [
                'id' => $this->lead->id, 'reference' => $this->lead->reference, 'name' => $this->lead->name,
            ] : null),

            // ── Qualification ─────────────────────────────────────────────
            'qualification' => [
                'account_weight'                => $this->account_weight,
                'knows_procurement'             => $this->knows_procurement,
                'deal_registered'               => $this->deal_registered,
                'compliance_pressure'           => $this->compliance_pressure,
                'oem_pressure'                  => $this->oem_pressure,
                'prior_deal_closed'             => $this->prior_deal_closed,
                'alignment_with_customer'       => $this->alignment_with_customer,
                'alignment_with_oem_financials' => $this->alignment_with_oem_financials,
                'alignment_with_oem_solution'   => $this->alignment_with_oem_solution,
                'alignment_with_oem_support'    => $this->alignment_with_oem_support,
                'budget_confirmed_by_customer'  => $this->budget_confirmed_by_customer,
                'gap_analysis'                  => $this->gap_analysis,
            ],

            /*
             * Computed here rather than stored: it depends on today's date
             * through the closing quarter, so a stored copy goes stale exactly
             * when the quarter turns and somebody looks at the pipeline.
             */
            'score'      => $this->score,
            'score_max'  => OpportunityScore::MAX,
            'score_band' => $this->score_band,

            'commitment' => [
                'is_committed'      => $this->is_committed,
                'year'              => $this->committed_year,
                'quarter'           => $this->committed_quarter,
                'discuss_weekly'    => $this->discuss_weekly,
            ],

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
