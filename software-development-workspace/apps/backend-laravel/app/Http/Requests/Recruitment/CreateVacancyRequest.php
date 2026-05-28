<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class CreateVacancyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isOrgHR(app('current.organization_id'));
    }

    public function rules(): array
    {
        return [
            'name'                    => 'required|string|max:150',
            'job_title'               => 'required|string|max:150',
            'workflow_id'             => 'required|integer|exists:recruitment_workflows,id',
            'template_id'             => 'nullable|integer|exists:vacancy_templates,id',
            'department_id'           => 'nullable|integer|exists:departments,id',
            'designation_id'          => 'nullable|integer|exists:designations,id',
            'hiring_manager_id'       => 'nullable|integer|exists:users,id',
            'location'                => 'nullable|string|max:150',
            'open_positions'          => 'integer|min:1',
            'status'                  => 'in:draft,open,closed,cancelled',
            'description'             => 'nullable|string',
            'closing_date'            => 'nullable|date',
            'application_form_schema' => 'nullable|array',
        ];
    }
}
