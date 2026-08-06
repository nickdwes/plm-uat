<?php

namespace App\Http\Resources\Pipeline;

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
            'source'    => $this->source,
            'status'    => $this->status,
            'priority'  => $this->priority,
            'deadline'  => $this->deadline?->toDateString(),
            'website'   => $this->website,

            'problem_statement' => $this->problem_statement,
            'proposed_solution' => $this->proposed_solution,

            'account' => $this->whenLoaded('account', fn () => $this->account ? [
                'id' => $this->account->id, 'name' => $this->account->name,
            ] : null),

            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'id' => $this->owner->id, 'name' => $this->owner->full_name,
            ] : null),

            'team' => $this->whenLoaded('team', fn () => $this->team->map(fn ($m) => [
                'id'       => $m->id,
                'employee' => ['id' => $m->employee?->id, 'name' => $m->employee?->full_name],
                'role'     => $m->role,
            ])),

            /*
             * What it became. Present rather than encoded in the status, which
             * is the whole reason the legacy's status could not be counted on.
             */
            'opportunity' => $this->whenLoaded('opportunity', fn () => $this->opportunity ? [
                'id' => $this->opportunity->id, 'reference' => $this->opportunity->reference,
                'name' => $this->opportunity->name,
            ] : null),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
