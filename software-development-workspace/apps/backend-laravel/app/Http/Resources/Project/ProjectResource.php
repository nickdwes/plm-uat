<?php

namespace App\Http\Resources\Project;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /*
         * The contract value is commercial, gated the same way the deal's is.
         * A project screen is not a way round the elevation.
         */
        $showsMoney = $this->opportunity
            && ($request->user()?->can('viewCommercials', $this->opportunity) ?? false);

        return [
            'id'          => $this->id,
            'reference'   => $this->reference,
            'name'        => $this->name,
            'description' => $this->description,
            'status'      => $this->status,
            'priority'    => $this->priority,

            'deadline'     => $this->deadline?->toDateString(),
            'started_on'   => $this->started_on?->toDateString(),
            'delivered_on' => $this->delivered_on?->toDateString(),

            /*
             * Two different questions, kept apart. `is_overdue` is about a
             * project still running; `was_late` is about one already delivered
             * and is null until it is — a project in flight is not a failure.
             */
            'is_overdue' => $this->is_overdue,
            'was_late'   => $this->was_late,

            'contract_value'      => $showsMoney ? $this->contract_value : null,
            'commercials_visible' => $showsMoney,

            'account' => $this->whenLoaded('account', fn () => $this->account ? [
                'id' => $this->account->id, 'name' => $this->account->name,
                'initials' => $this->account->initials,
            ] : null),

            'opportunity' => $this->whenLoaded('opportunity', fn () => $this->opportunity ? [
                'id' => $this->opportunity->id, 'reference' => $this->opportunity->reference,
                'name' => $this->opportunity->name,
            ] : null),

            'project_lead' => $this->whenLoaded('projectLead', fn () => $this->projectLead ? [
                'id' => $this->projectLead->id, 'name' => $this->projectLead->full_name,
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
