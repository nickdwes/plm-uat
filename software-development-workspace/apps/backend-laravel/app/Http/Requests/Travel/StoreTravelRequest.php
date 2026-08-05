<?php

namespace App\Http\Requests\Travel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTravelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the controller authorizes through the policy
    }

    public function rules(): array
    {
        $orgId = app('current.organization_id');

        return [
            // Membership in this organisation is checked in the service, which
            // has to consider secondments as well as primary placement.
            'employee_id' => ['required', 'integer', 'exists:employees,id'],

            // Every reference is confirmed to belong to this organisation.
            // `exists` alone would let a request name another tenant's
            // customer or destination, which reads as ordinary data on screen.
            'account_id' => ['nullable', 'integer',
                Rule::exists('accounts', 'id')->where('organization_id', $orgId)],
            'travel_destination_id' => ['nullable', 'integer',
                Rule::exists('travel_destinations', 'id')->where('organization_id', $orgId)],

            'travel_mode'     => ['nullable', 'string', 'max:32'],
            'departure_date'  => ['required', 'date'],
            // Same-day travel is one day, so equal dates are valid.
            'return_date'     => ['required', 'date', 'after_or_equal:departure_date'],
            'purpose'         => ['nullable', 'string', 'max:5000'],
            'business_impact' => ['nullable', 'string', 'max:5000'],
            'currency'        => ['sometimes', 'string', 'size:3'],
            'advance_required'=> ['nullable', 'numeric', 'min:0'],
        ];
    }
}
