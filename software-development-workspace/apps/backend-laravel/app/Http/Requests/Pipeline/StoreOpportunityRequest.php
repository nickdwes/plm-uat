<?php

namespace App\Http\Requests\Pipeline;

use App\Models\Pipeline\Opportunity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOpportunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Converting a lead needs no form.
     *
     * Qualifying a lead is what turns it into a deal, and that is one gesture
     * on the lead's own screen — not an invitation to retype the customer's
     * name underneath their name. The service already falls back to the lead
     * for both, but the rules below run first and would have refused before it
     * ever got the chance, so the defaults are filled in here.
     *
     * Only for the convert route. Creating a deal from nothing still has to
     * say what it is and who it is with.
     */
    protected function prepareForValidation(): void
    {
        $lead = $this->route('lead');

        if (! $lead) {
            return;
        }

        $this->merge([
            'name'       => $this->input('name') ?: $lead->name,
            'account_id' => $this->input('account_id') ?: $lead->account_id,
        ]);
    }

    /**
     * A lead with no customer on it cannot convert, and "the account id field
     * is required" is not what to tell somebody who never saw a field.
     */
    public function messages(): array
    {
        return $this->route('lead')
            ? ['account_id.required' => 'Name the customer on this lead before qualifying it — a deal has to be with somebody.']
            : [];
    }

    public function rules(): array
    {
        $orgId = app('current.organization_id');

        $employee = fn () => Rule::exists('employees', 'id')->where('primary_organization_id', $orgId);

        return [
            'name' => ['required', 'string', 'max:255'],

            // Required, unlike a lead's. A deal has to be with somebody, or
            // the revenue it carries is against nobody.
            'account_id' => ['required', 'integer',
                Rule::exists('accounts', 'id')->where('organization_id', $orgId)->whereNull('deleted_at')],

            'description'      => ['nullable', 'string', 'max:20000'],
            'opportunity_type' => ['nullable', Rule::in(Opportunity::TYPES)],
            'practice'         => ['nullable', 'string', 'max:255'],
            'amount'           => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'expected_close_date' => ['nullable', 'date'],

            'opportunity_phase_id' => ['nullable', 'integer',
                Rule::exists('opportunity_phases', 'id')->where('organization_id', $orgId)],

            'owner_id'        => ['nullable', 'integer', $employee()],
            'presale_lead_id' => ['nullable', 'integer', $employee()],

            // ── Qualification ─────────────────────────────────────────────
            'account_weight' => ['nullable', Rule::in(Opportunity::WEIGHTS)],
            'knows_procurement'             => ['nullable', 'boolean'],
            'deal_registered'               => ['nullable', 'boolean'],
            'compliance_pressure'           => ['nullable', 'boolean'],
            'oem_pressure'                  => ['nullable', 'boolean'],
            'prior_deal_closed'             => ['nullable', 'boolean'],
            'alignment_with_customer'       => ['nullable', 'boolean'],
            'alignment_with_oem_financials' => ['nullable', 'boolean'],
            'alignment_with_oem_solution'   => ['nullable', 'boolean'],
            'alignment_with_oem_support'    => ['nullable', 'boolean'],
            'budget_confirmed_by_customer'  => ['nullable', 'boolean'],
            'gap_analysis'                  => ['nullable', 'string', 'max:20000'],

            // ── Commit ────────────────────────────────────────────────────
            'is_committed'      => ['sometimes', 'boolean'],
            'committed_year'    => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'committed_quarter' => ['nullable', 'integer', 'min:1', 'max:4'],
            'discuss_weekly'    => ['sometimes', 'boolean'],
        ];
    }

    /**
     * A commitment without a quarter is not a commitment.
     *
     * The legacy allowed it — the flag was set on 38 rows but only 33 carried
     * a quarter — so five deals were committed to no particular time, which
     * the pipeline report then could not place.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($this->boolean('is_committed')
                && (! $this->filled('committed_year') || ! $this->filled('committed_quarter'))) {
                $v->errors()->add('committed_quarter', 'Say which quarter the deal is committed to.');
            }
        });
    }
}
