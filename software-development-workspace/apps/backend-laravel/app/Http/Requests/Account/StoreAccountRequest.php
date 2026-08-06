<?php

namespace App\Http\Requests\Account;

use App\Models\Account\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the controller authorizes through the policy
    }

    public function rules(): array
    {
        $orgId = app('current.organization_id');

        return [
            'name'   => [
                'required', 'string', 'max:255',
                // Scoped to the organisation and ignoring soft-deleted rows:
                // a duplicate customer is the main way a customer master rots,
                // with half the history on each record.
                Rule::unique('accounts', 'name')
                    ->where(fn ($q) => $q->where('organization_id', $orgId)->whereNull('deleted_at'))
                    ->ignore($this->route('account')),
            ],
            'status' => ['sometimes', Rule::in(Account::STATUSES)],

            // Every foreign key is confirmed to belong to this organisation.
            // `exists` alone would accept another tenant's sector.
            'account_manager_id'   => ['nullable', 'integer', Rule::exists('employees', 'id')->where('primary_organization_id', $orgId)],
            'account_type_id'      => ['nullable', 'integer', Rule::exists('account_types', 'id')->where('organization_id', $orgId)],
            'account_sector_id'    => ['nullable', 'integer', Rule::exists('account_sectors', 'id')->where('organization_id', $orgId)],
            'account_subsector_id' => ['nullable', 'integer', Rule::exists('account_subsectors', 'id')->where('organization_id', $orgId)],

            'country'             => ['nullable', 'string', 'size:2'],
            'city'                => ['nullable', 'string', 'max:120'],
            'physical_location'   => ['nullable', 'string', 'max:255'],
            'existing_products'   => ['nullable', 'string', 'max:255'],
            'budget_range'        => ['nullable', 'string', 'max:64'],
            'number_of_branches'  => ['nullable', 'integer', 'min:0', 'max:100000'],
            'number_of_employees' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'fund_source'         => ['nullable', 'string', 'max:255'],
            'vat_registered'      => ['sometimes', 'boolean'],
            'tax_pin'             => ['nullable', 'string', 'max:64'],
            'details'             => ['nullable', 'string', 'max:20000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('country')) {
            $this->merge(['country' => strtoupper($this->input('country'))]);
        }
    }
}
