<?php

namespace App\Http\Requests\Training;

use App\Models\Training\CompetencyItem;
use Illuminate\Foundation\Http\FormRequest;

class CreateCompetencyRequest extends FormRequest
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
        return $this->user()?->can('create', CompetencyItem::class) ?? false;
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
