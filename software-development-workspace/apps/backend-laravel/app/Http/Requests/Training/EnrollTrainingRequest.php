<?php

namespace App\Http\Requests\Training;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnrollTrainingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Employees can self-enroll; HR/managers can assign to others
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $employeeId = $this->input('employee_id') ?? auth()->user()->employee?->id;

        return [
            'employee_id'      => 'nullable|integer|exists:employees,id',
            'training_item_id' => [
                'required',
                'integer',
                'exists:training_items,id',
                Rule::unique('employee_training_records')
                    ->where('employee_id', $employeeId)
                    ->where('training_item_id', $this->input('training_item_id'))
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'training_item_id.unique' => 'Employee is already enrolled in this training item.',
        ];
    }
}

