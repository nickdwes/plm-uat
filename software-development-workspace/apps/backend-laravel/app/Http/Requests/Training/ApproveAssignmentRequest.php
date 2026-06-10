<?php

namespace App\Http\Requests\Training;

use Illuminate\Foundation\Http\FormRequest;

class ApproveAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'notes' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'notes.max' => 'Approval notes cannot exceed 500 characters.',
        ];
    }
}
