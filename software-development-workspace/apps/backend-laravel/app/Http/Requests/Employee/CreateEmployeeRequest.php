<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class CreateEmployeeRequest extends FormRequest
{
    /**
     * Deferred to the policy, so there is one answer rather than two.
     *
     * This checked a role name directly, which meant a role granted
     * `employees.create` was refused here while the policy would have allowed
     * it — the same disagreement the vacancy request had.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\Employee::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // Identity
            'first_name'           => ['required', 'string', 'max:100'],
            'last_name'            => ['required', 'string', 'max:100'],
            'middle_name'          => ['nullable', 'string', 'max:100'],
            'preferred_name'       => ['nullable', 'string', 'max:100'],
            'date_of_birth'        => ['nullable', 'date', 'before:today'],
            'gender'               => ['nullable', 'in:male,female,other,prefer_not_to_say'],
            'marital_status'       => ['nullable', 'string', 'max:24'],
            'national_id'          => ['nullable', 'string', 'max:20', 'unique:employees,national_id'],
            'passport_number'      => ['nullable', 'string', 'max:30'],
            'tax_id'               => ['nullable', 'string', 'max:30'],
            'nssf_number'          => ['nullable', 'string', 'max:30'],
            'nhif_number'          => ['nullable', 'string', 'max:30'],

            // Contact
            'personal_email'       => ['nullable', 'email', 'max:150'],
            'work_email'           => ['nullable', 'email', 'max:150', 'unique:employees,work_email'],
            'phone'                => ['nullable', 'string', 'max:30'],
            'address'              => ['nullable', 'string', 'max:255'],
            'city'                 => ['nullable', 'string', 'max:100'],
            // Kenyan counties. The country code on its own is too coarse for
            // anything a Kenyan employer has to file.
            'county'               => ['nullable', 'string', 'max:64'],
            'country'              => ['nullable', 'string', 'size:2'],
            'residential_address'  => ['nullable', 'string', 'max:255'],
            'photo_url'            => ['nullable', 'string', 'max:500'],

            // Org placement
            'department_id'        => ['nullable', 'integer', 'exists:departments,id'],
            'designation_id'       => ['nullable', 'integer', 'exists:designations,id'],
            'job_grade_id'         => ['nullable', 'integer', 'exists:job_grades,id'],
            'reports_to'           => ['nullable', 'integer', 'exists:employees,id'],

            // Employment
            'employment_type'      => ['required', 'in:full_time,part_time,contract,intern,attachment,casual'],
            'date_hired'           => ['required', 'date'],
            'probation_end_date'   => ['nullable', 'date', 'after:date_hired'],
            'contract_end_date'    => ['nullable', 'date', 'after:date_hired'],

            /*
             * The wizard's Save as Draft.
             *
             * A half-filled record must not appear in the employee list or be
             * counted as headcount, so it is created as a draft and only
             * becomes active when the wizard is completed.
             */
            'status'               => ['nullable', 'in:draft,active,probation,on_leave,suspended,exited'],

            // Compensation. Held here because the wizard collects it, and
            // because a grade gives a band rather than a figure.
            'basic_salary'         => ['nullable', 'numeric', 'min:0'],
            'house_allowance'      => ['nullable', 'numeric', 'min:0'],
            'transport_allowance'  => ['nullable', 'numeric', 'min:0'],
            'medical_allowance'    => ['nullable', 'numeric', 'min:0'],
            'other_allowances'     => ['nullable', 'numeric', 'min:0'],
            'insurance_relief'     => ['nullable', 'numeric', 'min:0'],

            // Benefits
            'medical_plan'           => ['nullable', 'string', 'max:255'],
            'pension_provider'       => ['nullable', 'string', 'max:255'],
            'benefit_medical'        => ['nullable', 'boolean'],
            'benefit_life_insurance' => ['nullable', 'boolean'],
            'benefit_pension'        => ['nullable', 'boolean'],
            'benefit_wiba'           => ['nullable', 'boolean'],
            'benefit_group_life'     => ['nullable', 'boolean'],
            'benefits_effective_date'=> ['nullable', 'date'],

            // Compliance
            'nssf_registered'      => ['nullable', 'boolean'],
            'nhif_registered'      => ['nullable', 'boolean'],
            'kra_registered'       => ['nullable', 'boolean'],
            'has_work_permit'      => ['nullable', 'boolean'],
            'work_permit_number'   => ['nullable', 'string', 'max:50'],
            'work_permit_expiry'   => ['nullable', 'date'],

            // Secondments
            'secondments'                    => ['nullable', 'array'],
            'secondments.*.org_id'           => ['required_with:secondments', 'integer', 'exists:organizations,id'],
            'secondments.*.role'             => ['nullable', 'string', 'max:150'],
            'secondments.*.scope'            => ['nullable', 'string', 'max:50'],
            // Why they are in the second organisation — it changes what the
            // allocation means and who is expected to pay for it.
            'secondments.*.engagement_type'  => ['nullable', 'string', 'max:32'],
            // A secondment at nought per cent is not happening.
            'secondments.*.allocation_pct'   => ['nullable', 'integer', 'min:1', 'max:100'],
            'secondments.*.is_billable'      => ['nullable', 'boolean'],
            'secondments.*.start_date'       => ['nullable', 'date'],
            'secondments.*.end_date'         => ['nullable', 'date', 'after:secondments.*.start_date'],
            'secondments.*.notes'            => ['nullable', 'string', 'max:1000'],
        ];
    }
}
