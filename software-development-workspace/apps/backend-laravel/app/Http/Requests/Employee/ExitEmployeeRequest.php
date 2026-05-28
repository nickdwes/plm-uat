<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class ExitEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isOrgHR(app('current.organization_id'));
    }

    public function rules(): array
    {
        return [
            'date_exited' => ['required', 'date', 'before_or_equal:today'],
            'exit_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
