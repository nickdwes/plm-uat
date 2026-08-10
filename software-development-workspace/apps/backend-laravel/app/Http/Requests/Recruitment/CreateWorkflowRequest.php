<?php

namespace App\Http\Requests\Recruitment;

use App\Policies\BasePolicy;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Http\FormRequest;

class CreateWorkflowRequest extends FormRequest
{
    /**
     * Answered from the permission catalogue rather than a role name.
     *
     * A hiring workflow is the pipeline every candidate is moved through, so
     * changing one changes how recruitment works for everybody.
     * There is no policy for it, so the permission is checked here directly —
     * but it is checked, which it was not: a role granted `recruitment.manage`
     * was refused while anybody whose role is named HR was let through.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::RECRUITMENT_MANAGE) ?? false;
    }

    public function rules(): array
    {
        return [
            'name'        => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'is_default'  => 'boolean',
            'stages'      => 'nullable|array',
            'stages.*.name'       => 'required|string|max:100',
            'stages.*.stage_type' => 'required|in:application,screening,interview,decision,offer,hired,rejected',
        ];
    }
}
