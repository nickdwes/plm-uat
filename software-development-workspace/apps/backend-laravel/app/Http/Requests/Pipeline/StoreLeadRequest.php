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

            /*
             * A lead names the customer it is for.
             *
             * This was nullable, on the reasoning that a lead can arrive before
             * anybody has opened a customer record. That reasoning was
             * overruled: a lead with no customer counts towards nobody's book,
             * never appears on the customer's record, and the person who knows
             * which company it came from is the one raising it — not whoever
             * picks it up three weeks later.
             *
             * The column stays nullable so an import of legacy leads without
             * one still loads; they are then unsaveable until a customer is
             * chosen, which is the right prompt rather than a silent gap.
             */
            'account_id' => ['required', 'integer',
                Rule::exists('accounts', 'id')->where('organization_id', $orgId)->whereNull('deleted_at')],

            'source'   => ['nullable', 'string', 'max:120'],
            // Board statuses only. `converted` is set by converting and
            // `qualified` is no longer a state a lead comes to rest in — both
            // would otherwise be reachable by editing a field.
            'status'   => ['sometimes', Rule::in(Lead::BOARD_STATUSES)],
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
            'account_id.required' => 'Choose the customer this lead is for.',
            'account_id.exists'   => 'That customer is not on file for this organisation.',
            'owner_id.exists'   => 'That person is not an employee of this organisation.',
        ];
    }
}
