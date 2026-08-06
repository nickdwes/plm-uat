<?php

namespace App\Http\Resources\Account;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'reference'         => $this->reference,
            'name'              => $this->name,
            'initials'          => $this->initials,
            'status'            => $this->status,
            'country'           => $this->country,
            'city'              => $this->city,
            'physical_location' => $this->physical_location,
            'enrolled_on'       => $this->enrolled_on?->toDateString(),
            'last_contact_at'   => $this->last_contact_at?->toIso8601String(),

            'account_manager'   => $this->whenLoaded('accountManager', fn () => [
                'id'   => $this->accountManager?->id,
                'name' => $this->accountManager?->full_name,
            ]),
            'type'              => $this->whenLoaded('type', fn () => ['id' => $this->type?->id, 'name' => $this->type?->name]),

            /*
             * The screens call this Industry. It is the sector we already
             * hold — a second column would give two answers to what business
             * a customer is in.
             */
            'sector'            => $this->whenLoaded('sector', fn () => ['id' => $this->sector?->id, 'name' => $this->sector?->name]),
            'subsector'         => $this->whenLoaded('subsector', fn () => ['id' => $this->subsector?->id, 'name' => $this->subsector?->name]),

            'number_of_branches'  => $this->number_of_branches,
            'number_of_employees' => $this->number_of_employees,
            'fund_source'         => $this->fund_source,
            'existing_products'   => $this->existing_products,
            'budget_range'        => $this->budget_range,

            /*
             * The screens show a band — "250–500 Employees". Derived from the
             * headcount we already hold rather than stored beside it, so a
             * customer cannot be 300 employees and "1000+" at once.
             */
            'organization_size'   => $this->organization_size,
            'lead_source'         => $this->lead_source,
            'pipeline_stage'      => $this->pipeline_stage,
            'vat_registered'      => $this->vat_registered,
            'tax_pin'             => $this->tax_pin,
            'details'             => $this->details,

            /*
             * Who to call, flattened onto the record because the customer list
             * shows a contact person per row and would otherwise load every
             * contact of every customer to find one.
             */
            'primary_contact'   => $this->when(
                $this->relationLoaded('primaryContact') || $this->relationLoaded('contacts'),
                function () {
                    // The list loads only the primary; the record loads them
                    // all. Either way this answers the same question.
                    $contact = $this->relationLoaded('primaryContact')
                        ? $this->primaryContact->first()
                        : ($this->contacts->firstWhere('is_primary', true) ?? $this->contacts->first());

                    return $contact ? [
                        'id'    => $contact->id,
                        'name'  => $contact->full_name,
                        'email' => $contact->email,
                        'phone' => $contact->phone,
                        'role'  => $contact->designation,
                    ] : null;
                },
            ),

            'contacts'    => AccountContactResource::collection($this->whenLoaded('contacts')),
            'contracts'   => AccountContractResource::collection($this->whenLoaded('contracts')),
            'attachments' => AccountAttachmentResource::collection($this->whenLoaded('attachments')),
            'activities'  => AccountActivityResource::collection($this->whenLoaded('activities')),

            'team'       => $this->whenLoaded('team', fn () => $this->team->map(fn ($m) => [
                'id'       => $m->id,
                'employee' => ['id' => $m->employee?->id, 'name' => $m->employee?->full_name],
                'role'     => $m->role,
            ])),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
