<?php

namespace App\Http\Requests\Roster;

use App\Models\Roster\RosterAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id'     => ['required', 'integer', 'exists:employees,id'],
            'shift_type_id'   => ['nullable', 'integer', 'exists:roster_shift_types,id'],
            'assignment_date' => ['required', 'date'],
            'type'            => ['sometimes', Rule::in([
                RosterAssignment::TYPE_SHIFT,
                RosterAssignment::TYPE_DAY_OFF,
                RosterAssignment::TYPE_LEAVE,
            ])],
            'notes'           => ['nullable', 'string', 'max:500'],
        ];
    }
}
