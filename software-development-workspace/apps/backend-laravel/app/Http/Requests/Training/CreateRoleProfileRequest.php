<?php

namespace App\Http\Requests\Training;

use Illuminate\Foundation\Http\FormRequest;

class CreateRoleProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isOrgHR(app('current.organization_id'));
    }

    public function rules(): array
    {
        return [
            'job_title'                          => 'required|string|max:150',
            'department_id'                      => 'nullable|integer|exists:departments,id',
            'description'                        => 'nullable|string',
            'competencies'                       => 'nullable|array',
            'competencies.*.competency_item_id'  => 'required|integer|exists:training_competency_items,id',
            'competencies.*.required_level'      => 'required|integer|min:1|max:5',
        ];
    }
}
