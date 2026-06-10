<?php

namespace App\Http\Requests\Roster;

use Illuminate\Foundation\Http\FormRequest;

class StoreShiftTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                   => ['required', 'string', 'max:100'],
            'shift_group_id'         => ['nullable', 'integer', 'exists:roster_shift_groups,id'],
            'start_time'             => ['required', 'date_format:H:i,H:i:s'],
            'end_time'               => ['required', 'date_format:H:i,H:i:s'],
            'break_start'            => ['nullable', 'date_format:H:i,H:i:s'],
            'break_duration_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'is_active'              => ['sometimes', 'boolean'],
            'color'                  => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }
}
