<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContactDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->employee !== null;
    }

    public function rules(): array
    {
        return [
            'phone'               => ['nullable', 'string', 'max:30'],
            'personal_email'      => ['nullable', 'email', 'max:150'],
            'address'             => ['nullable', 'string', 'max:255'],
            'residential_address' => ['nullable', 'string', 'max:1000'],
            'city'                => ['nullable', 'string', 'max:100'],
            'country'             => ['nullable', 'string', 'size:2'],
            'linkedin_profile'    => ['nullable', 'url', 'max:255'],
        ];
    }
}
