<?php

namespace App\Http\Requests\Account;

use App\Models\Account\AccountContact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category'        => ['sometimes', Rule::in(AccountContact::CATEGORIES)],
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

    /**
     * The form asks for one "Contact Name"; the record keeps two.
     *
     * Split here rather than stored as one string, because an import from the
     * legacy book already has the halves separately and a single column would
     * throw that apart. Everything after the first space is the surname —
     * wrong for some names, but recoverable by editing, which a merged column
     * would not be.
     */
    protected function prepareForValidation(): void
    {
        /*
         * `is_primary` was how a contact was promoted before categories
         * existed. Translated rather than dropped: leaving it in the rules but
         * ignoring it would mean a caller promoting somebody gets a 200 and no
         * promotion, which is worse than a rejection.
         */
        if ($this->boolean('is_primary') && ! $this->filled('category')) {
            $this->merge(['category' => 'primary']);
        }

        if ($this->filled('name') && ! $this->filled('first_name')) {
            $parts = preg_split('/\s+/', trim($this->input('name')), 2);

            $this->merge([
                'first_name' => $parts[0] ?? '',
                'last_name'  => $parts[1] ?? null,
            ]);
        }
    }
}
