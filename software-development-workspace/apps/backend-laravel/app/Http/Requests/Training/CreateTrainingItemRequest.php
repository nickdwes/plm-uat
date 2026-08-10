<?php

namespace App\Http\Requests\Training;

use App\Models\Training\TrainingItem;
use Illuminate\Foundation\Http\FormRequest;

class CreateTrainingItemRequest extends FormRequest
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
        return $this->user()?->can('create', TrainingItem::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'title'            => 'required|string|max:200',
            'description'      => 'nullable|string',
            'type'             => 'required|in:course,workshop,certification,exam_voucher,subscription,on_job,mentoring,conference',
            'delivery'         => 'required|in:in_person,virtual,e_learning,blended,self_study',
            'duration_hours'   => 'required|integer|min:0',
            'duration_minutes' => 'integer|min:0|max:59',
            'provider_name'    => 'nullable|string|max:200',
            'provider_url'     => 'nullable|url|max:500',
            'cost_currency'    => 'nullable|string|size:3',
            'estimated_cost'   => 'nullable|numeric|min:0',
            'tags'             => 'nullable|array',
            'tags.*'           => 'string|max:50',
            'is_active'         => 'boolean',
            'is_featured'       => 'boolean',
            'requires_approval' => 'boolean',
            'is_budgeted'       => 'boolean',
            'budget_amount'     => 'nullable|numeric|min:0',
            'max_enrollments'   => 'nullable|integer|min:1',
            'thumbnail_url'     => 'nullable|url|max:500',
            'competency_ids'    => 'nullable|array',
            'competency_ids.*'  => 'integer|exists:training_competency_items,id',
        ];
    }
}
