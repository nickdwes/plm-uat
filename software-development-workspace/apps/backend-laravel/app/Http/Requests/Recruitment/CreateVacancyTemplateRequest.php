<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class CreateVacancyTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isOrgHR(app('current.organization_id'));
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
