<?php

namespace App\Http\Requests\Recruitment;

use App\Support\Authorization\Permission;
use Illuminate\Foundation\Http\FormRequest;

class CreateVacancyTemplateRequest extends FormRequest
{
    /**
     * Answered from the permission catalogue rather than a role name.
     *
     * A vacancy template carries the application form schema and the screening
     * rules, so it is vacancy configuration rather than a vacancy.
     * There is no policy for it, so the permission is checked here directly —
     * but it is checked, which it was not: a role granted `vacancies.manage`
     * was refused while anybody whose role is named HR was let through.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::VACANCIES_MANAGE) ?? false;
    }

    public function rules(): array
    {
        return [
            'name'                    => 'required|string|max:120',
            'job_title'               => 'required|string|max:120',
            'workflow_id'             => 'nullable|integer|exists:recruitment_workflows,id',
            'department_id'           => 'nullable|integer|exists:departments,id',
            'designation_id'          => 'nullable|integer|exists:designations,id',
            'location'                => 'nullable|string|max:120',
            'default_positions'       => 'integer|min:1',
            'application_form_schema' => 'nullable|array',
            'screening_rules'         => 'nullable|array',
            'is_active'               => 'boolean',
        ];
    }
}
