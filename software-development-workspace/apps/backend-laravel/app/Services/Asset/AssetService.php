<?php

namespace App\Services\Asset;

use App\Models\Asset\Asset;
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
    public function assign(Asset $asset, Employee $employee, ?string $on = null): Asset
    {
        return DB::transaction(function () use ($asset, $employee, $on) {
            $asset->update([
                'assigned_to'   => $employee->getKey(),
                'assigned_date' => $on ?? now()->toDateString(),
                'status'        => Asset::STATUS_ASSIGNED,
            ]);

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
}
