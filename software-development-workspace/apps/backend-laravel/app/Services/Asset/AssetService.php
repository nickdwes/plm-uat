<?php

namespace App\Services\Asset;

use App\Models\Asset\Asset;
use App\Models\Asset\AssetCategory;
use App\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AssetService
{
    /**
     * @param array<string, mixed> $filters
     */
    public function list(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        // The organization global scope on the model applies the tenant filter;
        // it is not repeated here. Repeating it invites the two from drifting.
        $query = Asset::query()->with(['holder:id,first_name,last_name,employee_number', 'department:id,name']);

        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($category = $filters['category'] ?? null) {
            $query->where('category', $category);
        }

        if ($departmentId = $filters['department_id'] ?? null) {
            $query->where('department_id', $departmentId);
        }

        if (array_key_exists('assigned', $filters) && $filters['assigned'] !== null) {
            filter_var($filters['assigned'], FILTER_VALIDATE_BOOLEAN)
                ? $query->whereNotNull('assigned_to')
                : $query->whereNull('assigned_to');
        }

        // Software, subscription or physical — the register asks each kind
        // different questions, so it is often listed one kind at a time.
        /*
         * Licences with a seat left to give.
         *
         * The seat count is derived from allocations rather than stored, so the
         * spare figure cannot be a WHERE clause — but eager-loading the
         * allocations turns thirty-one queries into one, and the resource then
         * computes spare without touching the database again.
         */
        if (filter_var($filters['has_spare_seats'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereIn('asset_class', Asset::SEATED_CLASSES)
                ->whereNotNull('seats_total')
                ->where('seats_total', '>', 0)
                ->where('status', '!=', Asset::STATUS_RETIRED)
                ->with('seats');
        }

        if ($class = $filters['asset_class'] ?? null) {
            $query->where('asset_class', $class);
        }

        if ($holder = $filters['assigned_to'] ?? null) {
            $query->where('assigned_to', $holder);
        }

        if ($search = $filters['search'] ?? null) {
            $query->where(function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where('name', 'ilike', $like)
                    ->orWhere('asset_tag', 'ilike', $like)
                    ->orWhere('serial_number', 'ilike', $like)
                    ->orWhere('brand', 'ilike', $like)
                    ->orWhere('model', 'ilike', $like);
            });
        }

        // Convenience filters for the two questions an asset register is
        // actually asked: what is out of warranty, and what is due a service.
        if (filter_var($filters['warranty_expired'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNotNull('warranty_expiry_date')->whereDate('warranty_expiry_date', '<', now());
        }

        if (filter_var($filters['maintenance_due'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNotNull('next_maintenance')->whereDate('next_maintenance', '<=', now());
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(int $organizationId, array $data): Asset
    {
        $data['organization_id'] = $organizationId;

        // Checked against the questions this category actually asks, and
        // stripped of anything it does not.
        $data = $this->linkCategory($data, $organizationId);

        $data['custom_fields'] = app(CustomFieldValidator::class)->check(
            $data['category'] ?? null,
            $data['asset_class'] ?? 'physical',
            $data['custom_fields'] ?? [],
        );

        // An asset created already in someone's hands is assigned, not
        // available. Leaving the caller to set both invites the two disagreeing.
        if (! empty($data['assigned_to'])) {
            $data['status']      = Asset::STATUS_ASSIGNED;
            $data['assigned_date'] ??= now()->toDateString();
        }

        return Asset::create($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(Asset $asset, array $data): Asset
    {
        // Custody is changed through assign()/release(), which keep status and
        // holder consistent. Allowing it here would let an update quietly
        // reassign equipment.
        unset($data['assigned_to'], $data['assigned_date'], $data['organization_id']);

        /*
         * Answers are merged rather than replaced.
         *
         * An edit that touches one field should not silently wipe the rest,
         * and a form that only renders the current category's questions would
         * do exactly that on an asset that has answers from a previous one.
         */
        if (array_key_exists('category', $data)) {
            $data = $this->linkCategory($data, $asset->organization_id);
        }

        if (array_key_exists('custom_fields', $data)) {
            $data['custom_fields'] = app(CustomFieldValidator::class)->check(
                $data['category'] ?? $asset->category,
                $data['asset_class'] ?? $asset->asset_class,
                $data['custom_fields'] ?? [],
                $asset->custom_fields ?? [],
                // Its own values are not a clash with itself.
                $asset->getKey(),
            );
        }

        $asset->update($data);

        return $asset->fresh(['holder', 'department']);
    }

    /**
     * Hand an asset to an employee.
     *
     * Status and holder move together in one transaction — an asset that is
     * assigned but still marked available, or the reverse, is the state that
     * makes a register untrustworthy.
     */
    /**
     * Hand the asset over.
     *
     * `$accessories` is what physically goes with it — the charger, the mouse,
     * the bag. Recorded because the return form ticks the same list back off,
     * and "did the charger come back" is otherwise a question nobody can answer.
     * Null leaves whatever was there; an empty array means nothing went with it,
     * which is a different statement and worth being able to make.
     */
    public function assign(
        Asset $asset,
        Employee $employee,
        ?string $on = null,
        ?array $accessories = null,
        ?string $expectedReturn = null,
    ): Asset {
        return DB::transaction(function () use ($asset, $employee, $on, $accessories, $expectedReturn) {
            $asset->update(array_filter([
                'assigned_to'   => $employee->getKey(),
                'assigned_date' => $on ?? now()->toDateString(),
                'status'        => Asset::STATUS_ASSIGNED,
                'expected_return_date' => $expectedReturn,
                'assigned_accessories' => $accessories,
            ], fn ($v) => $v !== null));

            return $asset->fresh(['holder', 'department']);
        });
    }

    /**
     * Take an asset back.
     *
     * Returning to `available` is the default, but an item coming back broken
     * should go to maintenance rather than straight to the next person.
     */
    public function release(Asset $asset, string $status = Asset::STATUS_AVAILABLE): Asset
    {
        return DB::transaction(function () use ($asset, $status) {
            $asset->update([
                'assigned_to'   => null,
                'assigned_date' => null,
                'status'        => $status,
            ]);

            return $asset->fresh(['department']);
        });
    }

    /**
     * Resolve the category name to its configured record.
     *
     * The link is the truth and the string is a copy kept beside it, because
     * every list, filter, report and export reads the word — and routing all of
     * them through a join to display it would be a poor trade for correctness
     * nobody sees.
     *
     * A name that matches nothing configured leaves the link null rather than
     * being refused. Free text under "other" is a legitimate way to register
     * something the organisation has not got round to configuring.
     */
    private function linkCategory(array $data, int $organizationId): array
    {
        $name = $data['category'] ?? null;

        if ($name === null) {
            return $data;
        }

        $data['asset_category_id'] = AssetCategory::query()
            ->where('organization_id', $organizationId)
            ->where('asset_class', $data['asset_class'] ?? 'physical')
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->value('id');

        return $data;
    }
}
