<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isOrgHR(app('current.organization_id'));
    }

    public function rules(): array
    {
        return [
            'name'        => 'sometimes|string|max:120',
            'description' => 'nullable|string|max:500',
            'is_default'  => 'boolean',
            'stages'      => 'nullable|array',
            'stages.*.name'       => 'required_with:stages|string|max:100',
            'stages.*.stage_type' => 'required_with:stages|in:application,screening,interview,decision,offer,hired,rejected',
        ];
    }
}
