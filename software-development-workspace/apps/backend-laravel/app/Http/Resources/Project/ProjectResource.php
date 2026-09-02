<?php

namespace App\Http\Resources\Project;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /*
         * The contract value is commercial, gated the same way the deal's is —
         * a project screen is not a way round the elevation. A project with no
         * opportunity is internal: there is no deal to protect, so its numbers
         * stay open (the milestones endpoint applies the identical rule, and
         * the client's Budget tab follows this flag — the two must agree).
         */
        $showsMoney = $this->opportunity === null
            || ($request->user()?->can('viewCommercials', $this->opportunity) ?? false);

        return [
            'id'          => $this->id,
            'reference'   => $this->reference,
            'name'        => $this->name,
            'description' => $this->description,
            'status'      => $this->status,
            'rag'         => $this->rag,
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

            /*
             * How far along, as the cards show it. Null rather than 0 when
             * nothing is planned — an unplanned project is not 0% complete,
             * and a bar at zero says the wrong thing about it.
             */
            'progress'    => $this->progress,
            'task_count'  => $this->whenCounted('tasks'),

            'contract_value'      => $showsMoney ? $this->contract_value : null,
            'commercials_visible' => $showsMoney,

            /*
             * How the delivery was split with the OEM. Days are shown without
             * the commercial elevation — how many people a job took is a
             * resourcing fact — while what the OEM charged is masked.
             */
            'delivery' => [
                'vendor_partner' => $this->whenLoaded('vendorPartner', fn () => $this->vendorPartner ? [
                    'id' => $this->vendorPartner->id, 'name' => $this->vendorPartner->name,
                ] : null),
                'days_own'   => $this->delivery_days_own,
                'days_oem'   => $this->delivery_days_oem,
                'total_days' => $this->total_delivery_days,
                'oem_share'  => $this->oem_share,
                // Delivered share less planned share, in points. The number
                // this whole thing exists to produce.
                'oem_share_drift' => $this->oem_share_drift,
                'oem_ps_cost' => $showsMoney ? $this->oem_ps_cost : null,
            ],

            // ── Charter ──────────────────────────────────────────────────
            'scope'    => $this->scope,
            'charter'  => $this->charter,
            'pip'      => $this->pip,
            'product'  => $this->product,
            'project_email' => $this->project_email,
            'execution_start' => $this->execution_start?->toDateString(),
            'execution_end'   => $this->execution_end?->toDateString(),
            'charter_completion_date'  => $this->charter_completion_date?->toDateString(),
            'contract_completion_date' => $this->contract_completion_date?->toDateString(),

            'client_contact' => $this->whenLoaded('clientContact', fn () => $this->clientContact ? [
                'id' => $this->clientContact->id, 'name' => trim($this->clientContact->first_name . ' ' . $this->clientContact->last_name),
            ] : null),
            'client_sponsor' => $this->whenLoaded('clientSponsor', fn () => $this->clientSponsor ? [
                'id' => $this->clientSponsor->id, 'name' => trim($this->clientSponsor->first_name . ' ' . $this->clientSponsor->last_name),
            ] : null),
            'client_contact_id'         => $this->client_contact_id,
            'client_sponsor_contact_id' => $this->client_sponsor_contact_id,

            'account' => $this->whenLoaded('account', fn () => $this->account ? [
                'id' => $this->account->id, 'name' => $this->account->name,
                'initials' => $this->account->initials,
                // The client's own contacts, for the charter's people pickers.
                'contacts' => $this->account->relationLoaded('contacts')
                    ? $this->account->contacts->map(fn ($c) => [
                        'id' => $c->id,
                        'name' => trim($c->first_name . ' ' . $c->last_name),
                        'designation' => $c->designation,
                    ])->values()
                    : [],
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
