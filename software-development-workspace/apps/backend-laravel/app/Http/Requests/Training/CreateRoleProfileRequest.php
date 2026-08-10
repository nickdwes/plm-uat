<?php

namespace App\Http\Requests\Training;

use App\Models\Training\RoleCompetencyProfile;
use Illuminate\Foundation\Http\FormRequest;

class CreateRoleProfileRequest extends FormRequest
{
    /**
     * Deferred to the policy, so there is one answer rather than two.
     *
     * This checked a role name, which meant a role granted `training.manage`
     * was refused here whatever the policy said, and anybody whose role happens
     * to be called HR was allowed regardless of what it grants.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', RoleCompetencyProfile::class) ?? false;
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
