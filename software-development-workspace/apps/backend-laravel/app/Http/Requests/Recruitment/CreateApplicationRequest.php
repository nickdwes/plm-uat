<?php

namespace App\Http\Requests\Recruitment;

use App\Models\Recruitment\Application;
use Illuminate\Foundation\Http\FormRequest;

class CreateApplicationRequest extends FormRequest
{
    /**
     * Deferred to the policy, so there is one answer rather than two.
     *
     * Managing somebody is not the same as being allowed to put a candidate
     * forward for a role.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Application::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'candidate_id' => 'required|integer|exists:candidates,id',
            'form_data'    => 'nullable|array',
        ];
    }
}
