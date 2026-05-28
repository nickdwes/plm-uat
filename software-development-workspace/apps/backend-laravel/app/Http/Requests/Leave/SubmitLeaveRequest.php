<?php

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;

class SubmitLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $hasScattered = ! empty($this->input('scattered_dates'));
        $isHalfDay    = (bool) $this->input('is_half_day');

        return [
            'leave_type_id'    => ['required', 'integer', 'exists:leave_types,id'],
            // from_date required unless half-day tells us the date via scattered_dates
            'from_date'        => [
                $hasScattered ? 'nullable' : 'required',
                'date',
                ...($isHalfDay ? [] : ['after_or_equal:today']),
            ],
            'to_date'          => [
                $hasScattered ? 'nullable' : 'required',
                'date',
                'after_or_equal:from_date',
            ],
            'reason'           => ['nullable', 'string', 'max:500'],
            'is_half_day'      => ['boolean'],
            'half_day_period'  => ['nullable', 'in:morning,afternoon'],
            'scattered_dates'  => ['nullable', 'array', 'min:1'],
            'scattered_dates.*'=> ['date', 'after_or_equal:today'],
        ];
    }
}
