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
            'registration_number'         => $this->registration_number,
            'tax_id'                      => $this->tax_id,
            'vendor_type'                 => $this->vendor_type,
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
            'notes'                       => $this->notes,
            // Timestamps
            'created_at'                  => $this->created_at?->toDateTimeString(),
            'updated_at'                  => $this->updated_at?->toDateTimeString(),
        ];
    }
}
