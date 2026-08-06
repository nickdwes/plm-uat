<?php

namespace App\Http\Requests\Pipeline;

use App\Models\Pipeline\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the controller authorizes through the policy
    }

    public function rules(): array
    {
        $orgId = app('current.organization_id');

        return [
            'name' => ['required', 'string', 'max:255'],

            // Nullable: a lead can arrive before anybody has opened a customer
            // record for whoever it came from. Scoped so it cannot be pointed
            // at another tenant's customer.
            'account_id' => ['nullable', 'integer',
                Rule::exists('accounts', 'id')->where('organization_id', $orgId)->whereNull('deleted_at')],

            'source'   => ['nullable', 'string', 'max:120'],
            'status'   => ['sometimes', Rule::in(Lead::STATUSES)],
            'priority' => ['sometimes', Rule::in(Lead::PRIORITIES)],
            'deadline' => ['nullable', 'date'],
            'website'  => ['nullable', 'string', 'max:255'],

            'problem_statement' => ['nullable', 'string', 'max:20000'],
            'proposed_solution' => ['nullable', 'string', 'max:20000'],

            'owner_id' => ['nullable', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $orgId)],
        ];
    }

    public function messages(): array
    {
        return [
            'account_id.exists' => 'That customer is not on file for this organisation.',
            'owner_id.exists'   => 'That person is not an employee of this organisation.',
        ];
    }
}
