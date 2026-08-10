<?php

namespace App\Http\Requests\Employee;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    /**
     * Deferred to the policy, so there is one answer rather than two.
     *
     * This checked a role name, which meant a role granted `employees.update`
     * was refused here whatever the policy said — the same disagreement the
     * create request and the vacancy request had.
     *
     * Authorised against the employee being edited rather than the class,
     * because the controller does not check the record itself and the policy is
     * the only thing standing between a request and somebody else's record.
     */
    public function authorize(): bool
    {
        $employee = Employee::withoutGlobalScope('organization')
            ->find($this->route('employee'));

        if (! $employee) {
            return false;
        }

        return $this->user()?->can('update', $employee) ?? false;
    }

    public function rules(): array
    {
        $employeeId = $this->route('employee');

        return [
            'first_name'           => ['sometimes', 'string', 'max:100'],
            'last_name'            => ['sometimes', 'string', 'max:100'],
            'middle_name'          => ['nullable', 'string', 'max:100'],
            'preferred_name'       => ['nullable', 'string', 'max:100'],
            'date_of_birth'        => ['nullable', 'date', 'before:today'],
            'gender'               => ['nullable', 'in:male,female,other,prefer_not_to_say'],
            'national_id'          => ['nullable', 'string', 'max:20', Rule::unique('employees', 'national_id')->ignore($employeeId)],
            'passport_number'      => ['nullable', 'string', 'max:30'],
            'tax_id'               => ['nullable', 'string', 'max:30'],
            'nssf_number'          => ['nullable', 'string', 'max:30'],
            'nhif_number'          => ['nullable', 'string', 'max:30'],

            'personal_email'       => ['nullable', 'email', 'max:150'],
            'work_email'           => ['nullable', 'email', 'max:150', Rule::unique('employees', 'work_email')->ignore($employeeId)],
            'phone'                => ['nullable', 'string', 'max:30'],
            'address'              => ['nullable', 'string', 'max:255'],
            'residential_address'  => ['nullable', 'string', 'max:1000'],
            'linkedin_profile'     => ['nullable', 'url', 'max:255'],
            'city'                 => ['nullable', 'string', 'max:100'],
            'country'              => ['nullable', 'string', 'size:2'],

            'department_id'        => ['nullable', 'integer', 'exists:departments,id'],
            'designation_id'       => ['nullable', 'integer', 'exists:designations,id'],
            'job_grade_id'         => ['nullable', 'integer', 'exists:job_grades,id'],
            'reports_to'           => ['nullable', 'integer', 'exists:employees,id'],

            'employment_type'      => ['sometimes', 'in:full_time,part_time,contract,intern,attachment,casual'],
            'date_hired'           => ['sometimes', 'date'],
            'probation_end_date'   => ['nullable', 'date'],
            'contract_end_date'    => ['nullable', 'date'],

            // Compensation
            'basic_salary'         => ['nullable', 'numeric', 'min:0'],
            'house_allowance'      => ['nullable', 'numeric', 'min:0'],
            'transport_allowance'  => ['nullable', 'numeric', 'min:0'],
            'medical_allowance'    => ['nullable', 'numeric', 'min:0'],
            'other_allowances'     => ['nullable', 'numeric', 'min:0'],
            'insurance_relief'     => ['nullable', 'numeric', 'min:0'],

            // Health (sensitive)
            'blood_group'          => ['nullable', 'string', 'max:10'],
            'allergies'            => ['nullable', 'string', 'max:2000'],
            'known_conditions'     => ['nullable', 'string', 'max:2000'],
            'disabilities'         => ['nullable', 'string', 'max:2000'],

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

            // Profile / career text fields
            'professional_summary' => ['nullable', 'string', 'max:5000'],
            'about_me'             => ['nullable', 'string', 'max:3000'],
            'hobbies_interests'    => ['nullable', 'string', 'max:1000'],
            'job_description'      => ['nullable', 'string', 'max:5000'],

            // Secondments
            'secondments'                    => ['nullable', 'array'],
            'secondments.*.org_id'           => ['required_with:secondments', 'integer', 'exists:organizations,id'],
            'secondments.*.role'             => ['nullable', 'string', 'max:150'],
            'secondments.*.scope'            => ['nullable', 'string', 'max:50'],
            'secondments.*.allocation_pct'   => ['nullable', 'integer', 'min:0', 'max:100'],
            'secondments.*.is_billable'      => ['nullable', 'boolean'],
            'secondments.*.start_date'       => ['nullable', 'date'],
            'secondments.*.end_date'         => ['nullable', 'date', 'after:secondments.*.start_date'],
            'secondments.*.notes'            => ['nullable', 'string', 'max:1000'],
        ];
    }
}
