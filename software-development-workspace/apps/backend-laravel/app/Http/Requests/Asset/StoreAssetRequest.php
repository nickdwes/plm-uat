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
            /*
             * What kind of thing this is, asked first because it decides which
             * of the rest apply.
             *
             * Defaults to physical for anything that does not say — every
             * caller that predates classes was registering hardware, and
             * refusing them would break the register to gain nothing.
             */
            'asset_class'   => ['nullable', Rule::in(Asset::CLASSES)],

            /*
             * What this belongs to.
             *
             * A subscription names the software product it pays for, so one
             * product carries several agreements — an enterprise agreement, an
             * executive tier, a monthly CSP — each with its own term, seats and
             * cost. Asking the product when it renews is asking the wrong
             * question of the wrong record.
             */
            'parent_asset_id' => ['nullable', 'integer', 'exists:assets,id'],

            // Checked against the category's own definitions in the service —
            // the rules are data, so they cannot be declared here.
            'custom_fields' => ['nullable', 'array'],

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

            /*
             * ── Things that expire ──
             *
             * A certificate, a domain, a licence term. Accepted for any class
             * because a laptop can have a support contract that ends, but they
             * are the whole point of the other two.
             */
            'starts_on'           => ['nullable', 'date'],
            /*
             * When the vendor stops supporting it. A date rather than a status,
             * because unsupported software becomes a security finding on a
             * particular day whether or not anybody changed a dropdown.
             */
            'end_of_support_on'   => ['nullable', 'date'],
            'expires_on'          => ['nullable', 'date', 'after_or_equal:starts_on'],
            'description'         => ['nullable', 'string', 'max:5000'],
            'auto_renews'         => ['nullable', 'boolean'],
            'renewal_notice_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'renewal_cost'        => ['nullable', 'numeric', 'min:0'],
            'estimated_renewal_cost' => ['nullable', 'numeric', 'min:0'],
            'renewal_reminders_on'   => ['nullable', 'boolean'],

            /*
             * ── Things bought by the seat ──
             *
             * Used cannot exceed total: a licence with more people on it than
             * seats paid for is the compliance finding the whole register
             * exists to prevent, and it should be refused at the door rather
             * than discovered in an audit.
             */
            /*
             * How many the batch was bought with. Seats in use are counted from
             * allocations, so there is nothing here to keep in step with them.
             */
            'seats_total' => ['nullable', 'integer', 'min:1'],

            'vendor_contact'  => ['nullable', 'string', 'max:255'],
            'support_contact' => ['nullable', 'string', 'max:255'],
            'sla'             => ['nullable', 'string', 'max:120'],

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
