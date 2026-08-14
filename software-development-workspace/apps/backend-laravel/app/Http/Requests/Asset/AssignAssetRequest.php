<?php

namespace App\Http\Requests\Asset;

use App\Models\Asset\Asset;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Assigning custody is a separate permission from editing the record, so this
 * authorizes against 'assign' rather than 'update'.
 */
class AssignAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $asset = $this->route('asset');

        return $asset instanceof Asset
            && ($this->user()?->can('assign', $asset) ?? false);
    }

    public function rules(): array
    {
        return [
            'employee_id'   => ['required', 'integer', 'exists:employees,id'],
            'assigned_date' => ['nullable', 'date'],

            /*
             * What goes out with it — charger, mouse, bag. The return form
             * ticks the same list back off, so this is what makes "did the
             * charger come back" an answerable question.
             */
            'accessories'   => ['nullable', 'array'],
            'accessories.*' => ['string', 'max:60'],

            /*
             * Left null when no date was agreed, which is not the same as
             * "due at the end of employment" and should not be written as
             * though it were.
             */
            'expected_return_date' => ['nullable', 'date', 'after_or_equal:assigned_date'],
        ];
    }
}
