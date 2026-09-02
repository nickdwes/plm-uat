<?php

namespace App\Http\Resources\Vendor;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Identity
            'id'                          => $this->id,
            'uuid'                        => $this->uuid,
            'vendor_number'               => $this->vendor_number,
            // Company info
            'name'                        => $this->name,
            /*
             * Organisation or individual. A consultant or an independent
             * contractor is a person, and the client hides the company-only
             * fields (registration number, tiers) accordingly.
             */
            'party_class'                 => $this->party_class ?? 'organization',
            /*
             * Whatever this party's roles actually ask about it — a
             * consultant's professional body, a distributor's territories.
             * Free-form by design: the questions are configuration.
             */
            'role_details'                => $this->role_details ?? [],
            'registration_number'         => $this->registration_number,
            'tax_id'                      => $this->tax_id,
            /*
             * Classification, in force and historic. `vendor_type` and
             * `is_oem_vendor` below are DERIVED from these and kept only for
             * the consumers that have not moved over — read `roles` here.
             */
            'roles'                       => $this->whenLoaded('stakeholderRoles', fn () => $this->stakeholderRoles
                ->map(fn ($r) => [
                    'id'          => $r->id,
                    'role'        => $r->role,
                    'label'       => $r->label(),
                    'valid_from'  => $r->valid_from?->toDateString(),
                    'valid_until' => $r->valid_until?->toDateString(),
                    'in_force'    => $r->isInForce(),
                    'notes'       => $r->notes,
                ])->values()),
            'active_roles'                => $this->whenLoaded('stakeholderRoles', fn () => $this->stakeholderRoles
                ->filter(fn ($r) => $r->isInForce())
                ->map(fn ($r) => $r->role)->values()),
            'vendor_type'                 => $this->vendor_type,
            'is_oem_vendor'               => $this->is_oem_vendor,
            'category'                    => $this->category,
            'description'                 => $this->description,
            // Partnership & fiscal
            'partnership_level'           => $this->partnership_level,
            'logo_url'                    => $this->logo_url,
            'fiscal_year_start_month'     => $this->fiscal_year_start_month,
            'renewal_anniversary_date'    => $this->renewal_anniversary_date?->toDateString(),
            'renewal_cost'                => $this->renewal_cost,
            'renewal_cost_currency'       => $this->renewal_cost_currency,
            'renewal_reminder_days'       => $this->renewal_reminder_days,
            'bank_name'                   => $this->bank_name,
            'bank_branch'                 => $this->bank_branch,
            'account_name'                => $this->account_name,
            'account_number'              => $this->account_number,
            'swift_code'                  => $this->swift_code,
            'payment_terms_days'          => $this->payment_terms_days,
            'credit_limit'                => $this->credit_limit === null ? null : (float) $this->credit_limit,
            'credit_limit_currency'       => $this->credit_limit_currency,
            // Contact
            'primary_contact_name'        => $this->primary_contact_name,
            'primary_contact_designation' => $this->primary_contact_designation,
            'contact_email'               => $this->contact_email,
            'contact_phone'               => $this->contact_phone,
            'website_url'                 => $this->website_url,
            // Address
            'physical_address'            => $this->physical_address,
            'city'                        => $this->city,
            'country'                     => $this->country,
            // Status
            'is_active'                   => $this->is_active,
            'onboarding_status'           => $this->onboarding_status,
            'approved_at'                 => $this->approved_at?->toDateTimeString(),
            'approved_by'                 => $this->approved_by,
            'rejection_reason'            => $this->rejection_reason,
            /*
             * What stands between this party and being traded with, computed
             * from the roles it holds and the paperwork it has produced —
             * never from a column somebody set by hand. Only present on the
             * detail view, which is the only place the roles are loaded.
             */
            'submitted_by'                => $this->submitted_by,
            'submitted_at'                => $this->submitted_at?->toDateTimeString(),
            'approvals'                   => $this->whenLoaded('approvals', fn () => $this->approvals
                ->map(fn ($a) => [
                    'id'         => $a->id,
                    'stage'      => $a->stage,
                    'label'      => $a->label(),
                    'sequence'   => $a->sequence,
                    'status'     => $a->status,
                    'comment'    => $a->comment,
                    'decided_at' => $a->decided_at?->toDateTimeString(),
                    'decided_by' => $a->decided_by,
                ])->values()),
            /*
             * A queued change names its FIELDS, never its values: a screen
             * that renders the proposed account number defeats the point of
             * making somebody confirm it.
             */
            'pending_change'              => $this->pending_change === null ? null : [
                'fields'      => array_keys($this->pending_change),
                'proposed_by' => $this->pending_change_by,
                'proposed_at' => $this->pending_change_at?->toDateTimeString(),
            ],
            'activation_blockers'         => $this->when(
                // Gated on `documents` rather than `stakeholderRoles`: the
                // list eager-loads roles for its chips, and computing this
                // there would query documents once per row.
                $this->relationLoaded('documents'),
                fn () => app(\App\Services\Vendor\VendorReadiness::class)->blockers($this->resource),
            ),
            'notes'                       => $this->notes,
            // Timestamps
            'created_at'                  => $this->created_at?->toDateTimeString(),
            'updated_at'                  => $this->updated_at?->toDateTimeString(),
        ];
    }
}
