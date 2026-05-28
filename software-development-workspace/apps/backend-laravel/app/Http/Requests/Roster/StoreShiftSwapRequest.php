<?php

namespace App\Http\Requests\Roster;

use Illuminate\Foundation\Http\FormRequest;

class StoreShiftSwapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'requester_assignment_id'   => ['required', 'integer', 'exists:roster_assignments,id'],
            'requested_assignment_id'   => ['nullable', 'integer', 'exists:roster_assignments,id'],
            'requested_employee_id'     => ['required', 'integer', 'exists:employees,id'],
            'requester_notes'           => ['nullable', 'string', 'max:500'],
        ];
    }
}
