<?php

namespace App\Http\Requests\Training;

use Illuminate\Foundation\Http\FormRequest;

class CreateCompetencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isOrgHR(app('current.organization_id'));
    }

    public function rules(): array
    {
        return [
            'name'                         => 'required|string|max:150',
            'description'                  => 'nullable|string',
            'category'                     => 'nullable|string|max:100',
            'proficiency_levels'           => 'nullable|array',
            'proficiency_levels.*.level'   => 'required|integer|min:1|max:5',
            'proficiency_levels.*.descriptor' => 'required|string|max:200',
        ];
    }
}
