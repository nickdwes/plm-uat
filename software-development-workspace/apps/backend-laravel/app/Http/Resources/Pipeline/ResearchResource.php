<?php

namespace App\Http\Resources\Pipeline;

use App\Models\Pipeline\Research;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResearchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'reference'   => $this->reference,
            'name'        => $this->name,
            'description' => $this->description,
            'script'      => $this->script,
            'findings'    => $this->findings,
            'status'      => $this->status,
            'priority'    => $this->priority,
            'deadline'    => $this->deadline?->toDateString(),

            /*
             * What the work is asking. Carried from the legacy, where it was
             * set on most rows — concept, architecture and integration are
             * answered by different people, so it decides who picks it up.
             */
            'scope' => [
                'concept'      => $this->covers_concept,
                'architecture' => $this->covers_architecture,
                'integration'  => $this->covers_integration,
            ],

            'account' => $this->whenLoaded('account', fn () => $this->account ? [
                'id' => $this->account->id, 'name' => $this->account->name,
                'initials' => $this->account->initials,
            ] : null),

            'lead' => $this->whenLoaded('lead', fn () => $this->lead ? [
                'id' => $this->lead->id, 'reference' => $this->lead->reference, 'name' => $this->lead->name,
            ] : null),

            'opportunity' => $this->whenLoaded('opportunity', fn () => $this->opportunity ? [
                'id' => $this->opportunity->id, 'reference' => $this->opportunity->reference,
                'name' => $this->opportunity->name,
            ] : null),

            'research_lead' => $this->whenLoaded('researchLead', fn () => $this->researchLead ? [
                'id' => $this->researchLead->id, 'name' => $this->researchLead->full_name,
            ] : null),

            'team' => $this->whenLoaded('team', fn () => $this->team->map(fn ($m) => [
                'id'       => $m->id,
                'employee' => ['id' => $m->employee?->id, 'name' => $m->employee?->full_name],
                'role'     => $m->role,
            ])),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
