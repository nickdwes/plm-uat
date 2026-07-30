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
        ];
    }
}
