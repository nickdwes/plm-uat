<?php

namespace App\Http\Requests\Account;

use App\Models\Account\AccountContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the controller authorizes through the policy
    }

    public function rules(): array
    {
        return [
            'name'            => ['required', 'string', 'max:255'],
            'contract_number' => ['nullable', 'string', 'max:120'],
            'type'            => ['nullable', Rule::in(AccountContract::TYPES)],
            'contract_value'  => ['nullable', 'numeric', 'min:0', 'max:99999999999.99'],
            'billing_cycle'   => ['nullable', Rule::in(AccountContract::BILLING_CYCLES)],
            'owner'           => ['nullable', 'string', 'max:255'],

            // Free text until Projects exists; see the migration.
            'project_reference' => ['nullable', 'string', 'max:255'],

            'start_date' => ['required', 'date'],
            // A contract that ends before it starts is a typo every time, and
            // it silently breaks "what expires in the next 90 days".
            'end_date'   => ['required', 'date', 'after_or_equal:start_date'],

            'status' => ['sometimes', Rule::in(AccountContract::STATUSES)],
            'notes'  => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function messages(): array
    {
        return ['end_date.after_or_equal' => 'The contract cannot end before it starts.'];
    }
}
