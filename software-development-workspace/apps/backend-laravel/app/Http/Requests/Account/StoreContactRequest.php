<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'           => ['nullable', 'string', 'max:32'],
            'first_name'      => ['required', 'string', 'max:128'],
            'last_name'       => ['nullable', 'string', 'max:128'],
            'designation'     => ['nullable', 'string', 'max:128'],
            'department'      => ['nullable', 'string', 'max:128'],
            'email'           => ['nullable', 'email', 'max:255'],
            'phone'           => ['nullable', 'string', 'max:32'],
            'alternate_phone' => ['nullable', 'string', 'max:32'],
            'is_primary'      => ['sometimes', 'boolean'],
            'is_active'       => ['sometimes', 'boolean'],
        ];
    }
}
