<?php

namespace App\Http\Requests\Training;

use Illuminate\Foundation\Http\FormRequest;

class RejectAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A rejection reason is required.',
            'reason.max' => 'Rejection reason cannot exceed 500 characters.',
        ];
    }
}
