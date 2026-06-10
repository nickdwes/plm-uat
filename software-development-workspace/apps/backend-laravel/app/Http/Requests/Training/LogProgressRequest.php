<?php

namespace App\Http\Requests\Training;

use Illuminate\Foundation\Http\FormRequest;

class LogProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'progress_percent' => 'required|integer|min:0|max:100',
            'notes'            => 'nullable|string|max:1000',
        ];
    }
}
