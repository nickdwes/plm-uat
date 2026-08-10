<?php

namespace App\Http\Requests\Employee;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class ExitEmployeeRequest extends FormRequest
{
    /**
     * Deferred to the policy, so there is one answer rather than two.
     *
     * Authorised against the employee being exited rather than the class: the
     * controller does not check the record, so this is the only thing standing
     * between a request and ending somebody else's employment.
     */
    public function authorize(): bool
    {
        $employee = Employee::withoutGlobalScope('organization')
            ->find($this->route('employee'));

        if (! $employee) {
            return false;
        }

        return $this->user()?->can('exit', $employee) ?? false;
    }

    public function rules(): array
    {
        return [
            'date_exited' => ['required', 'date', 'before_or_equal:today'],
            'exit_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
