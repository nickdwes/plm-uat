<?php

namespace App\Http\Requests\Asset;

use App\Models\Asset\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetRequest extends FormRequest
{
    /**
     * Delegates to the policy rather than returning true.
     *
     * A FormRequest whose authorize() returns true reads as "authorization
     * considered and granted" when it means "not implemented" — the pattern
     * that left several controllers in this codebase unguarded.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Asset::class) ?? false;
    }

    public function rules(): array
    {
        $orgId = app('current.organization_id');

        return [
            'name'          => ['required', 'string', 'max:255'],
            // Unique within the tenant, not globally — see the assets migration.
            'asset_tag'     => [
                'required', 'string', 'max:255',
                Rule::unique('assets', 'asset_tag')->where(fn ($q) => $q->where('organization_id', $orgId)),
            ],
            'category'      => ['required', 'string', 'max:255'],
            'brand'         => ['nullable', 'string', 'max:255'],
            'model'         => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],

            'status'          => ['nullable', Rule::in(Asset::STATUSES)],
            'lifecycle_stage' => ['nullable', 'string', 'max:255'],
            'condition'       => ['nullable', 'string', 'max:255'],

            'assigned_to'   => ['nullable', 'integer', 'exists:employees,id'],
            'assigned_date' => ['nullable', 'date'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'location'      => ['nullable', 'string', 'max:255'],

            'handover_required' => ['nullable', 'boolean'],

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
