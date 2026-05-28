<?php

namespace App\Http\Requests\Roster;

use Illuminate\Foundation\Http\FormRequest;

class UpsertRosterConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller handles authorization.
    }

    public function rules(): array
    {
        return [
            'primary_detail_shown'      => ['sometimes', 'in:total_shift_hours,allocated_hours'],
            'swap_shifts_enabled'        => ['sometimes', 'boolean'],
            'manager_approval_required'  => ['sometimes', 'boolean'],
        ];
    }
}
