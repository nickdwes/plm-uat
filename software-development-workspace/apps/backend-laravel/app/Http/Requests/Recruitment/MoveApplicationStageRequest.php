<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class MoveApplicationStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isManagerIn(app('current.organization_id'));
    }

    public function rules(): array
    {
        return [
            'stage_id' => 'required|integer|exists:workflow_stages,id',
            'notes'    => 'nullable|string|max:1000',
        ];
    }
}
