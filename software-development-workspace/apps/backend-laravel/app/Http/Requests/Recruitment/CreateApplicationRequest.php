<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class CreateApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isManagerIn(app('current.organization_id'));
    }

    public function rules(): array
    {
        return [
            'candidate_id' => 'required|integer|exists:candidates,id',
            'form_data'    => 'nullable|array',
        ];
    }
}
