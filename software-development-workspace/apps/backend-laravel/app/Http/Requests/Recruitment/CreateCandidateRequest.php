<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class CreateCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isManagerIn(app('current.organization_id'));
    }

    public function rules(): array
    {
        return [
            'first_name'   => 'required|string|max:80',
            'last_name'    => 'required|string|max:80',
            'email'        => 'required|email|max:200',
            'phone'        => 'nullable|string|max:30',
            'social_links' => 'nullable|array',
            'keywords'     => 'nullable|array',
            'source'       => 'nullable|string|max:80',
            'notes'        => 'nullable|string',
        ];
    }
}
