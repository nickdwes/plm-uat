<?php

namespace App\Http\Requests\Asset;

use App\Models\Asset\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $asset = $this->route('asset');

        return $asset instanceof Asset
            && ($this->user()?->can('update', $asset) ?? false);
    }

    public function rules(): array
    {
        $orgId = app('current.organization_id');
        $asset = $this->route('asset');

        return [
            // Checked against the category's own definitions in the service —
            // the rules are data, so they cannot be declared here.
            'custom_fields' => ['nullable', 'array'],
            'parent_asset_id' => ['nullable', 'integer', 'exists:assets,id'],

            'name'          => ['sometimes', 'string', 'max:255'],
            'asset_tag'     => [
                'sometimes', 'string', 'max:255',
                Rule::unique('assets', 'asset_tag')
                    ->ignore($asset?->getKey())
                    ->where(fn ($q) => $q->where('organization_id', $orgId)),
            ],
            'category'      => ['sometimes', 'string', 'max:255'],
            'brand'         => ['nullable', 'string', 'max:255'],
            'model'         => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],

            'status'          => ['sometimes', Rule::in(Asset::STATUSES)],
            'lifecycle_stage' => ['nullable', 'string', 'max:255'],
            'condition'       => ['nullable', 'string', 'max:255'],

            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'location'      => ['nullable', 'string', 'max:255'],

            'handover_required'  => ['nullable', 'boolean'],
            'handover_signed_at' => ['nullable', 'date'],

            'purchase_date'        => ['nullable', 'date'],
            'purchase_value'       => ['nullable', 'numeric', 'min:0'],
            'vendor'               => ['nullable', 'string', 'max:255'],
            'invoice_number'       => ['nullable', 'string', 'max:255'],
            'warranty_period'      => ['nullable', 'string', 'max:255'],
            'warranty_expiry_date' => ['nullable', 'date'],
            'procured_date'        => ['nullable', 'date'],
            'in_stock_date'        => ['nullable', 'date'],
            'next_maintenance'     => ['nullable', 'date'],

            'processor'        => ['nullable', 'string', 'max:255'],
            'ram'              => ['nullable', 'string', 'max:255'],
            'storage'          => ['nullable', 'string', 'max:255'],
            'operating_system' => ['nullable', 'string', 'max:255'],

            'notes' => ['nullable', 'string'],
        ];
    }
}
