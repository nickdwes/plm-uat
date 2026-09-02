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
            // Set once the learner has actually sat the exam — not gated to
            // 100% progress, since a passing score can arrive before the
            // learner has logged the rest of the course as done.
            'score'                    => 'nullable|numeric|min:0|max:100',
            'certificate_number'       => 'nullable|string|max:100',
            'certificate_expiry_date'  => 'nullable|date',
        ];
    }
}
