<?php

namespace App\Http\Requests\Training;

use Illuminate\Foundation\Http\FormRequest;

class BulkAssignTrainingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'training_item_id' => ['required', 'string', 'exists:training_items,id'],
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
            'due_date' => ['nullable', 'date', 'after:today'],
            'assignment_type' => ['required', 'in:mandatory,recommended'],
        ];
    }

    public function messages(): array
    {
        return [
            'training_item_id.required' => 'Training item is required',
            'training_item_id.exists' => 'Selected training item does not exist',
            'employee_ids.required' => 'At least one employee must be selected',
            'employee_ids.*.exists' => 'One or more selected employees do not exist',
            'due_date.after' => 'Due date must be in the future',
            'assignment_type.in' => 'Assignment type must be either mandatory or recommended',
        ];
    }
}
