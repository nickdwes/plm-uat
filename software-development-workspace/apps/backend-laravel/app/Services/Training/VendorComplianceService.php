<?php

namespace App\Services\Training;

use App\Models\Training\EmployeeTrainingRecord;
use App\Models\Training\VendorPartner;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 — Vendor Partner Compliance
 *
 * Computes per-vendor compliance status based on how many employees
 * hold completed certifications for each requirement.
 */
class VendorComplianceService
{
    // ── Vendor CRUD ───────────────────────────────────────────────────────────

    public function listVendors(int $organizationId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = VendorPartner::where('organization_id', $organizationId)
            ->with(['requiredCertifications'])
            ->orderBy('name');

        if (!empty($filters['search'])) {
            $query->where('name', 'ilike', '%' . $filters['search'] . '%');
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->paginate($perPage);
    }

    public function createVendor(int $organizationId, array $data): VendorPartner
    {
        $data['organization_id'] = $organizationId;
        return VendorPartner::create($data);
    }

    public function updateVendor(VendorPartner $vendor, array $data): VendorPartner
    {
        $vendor->update($data);
        return $vendor->fresh();
    }

    public function destroyVendor(VendorPartner $vendor): void
    {
        $vendor->delete();
    }

    // ── Requirements (pivot) ──────────────────────────────────────────────────

    /**
     * Sync the certification requirements for a vendor.
     * $requirements: [['training_item_id' => X, 'required_headcount' => N, 'notes' => '...'], ...]
     */
    public function syncRequirements(VendorPartner $vendor, array $requirements): void
    {
        $sync = collect($requirements)->keyBy('training_item_id')->map(function ($r) {
            return [
                'required_headcount' => $r['required_headcount'] ?? 1,
                'notes'              => $r['notes'] ?? null,
            ];
        })->all();

        $vendor->requiredCertifications()->sync($sync);
    }

    // ── Compliance calculation ────────────────────────────────────────────────

    /**
     * Compute full compliance summary for all vendors in the organisation.
     */
    public function summary(int $organizationId): array
    {
        $vendors = VendorPartner::where('organization_id', $organizationId)
            ->with(['requiredCertifications'])
            ->orderBy('name')
            ->get();

        return $vendors->map(fn($v) => $this->status($v))->values()->all();
    }

    /**
     * Per-vendor compliance status.
     *
     * Returns:
     *   status          : 'compliant' | 'at_risk' | 'non_compliant'
     *   certified_count : number of requirements currently met
     *   required_count  : total number of requirements
     *   expiring_soon   : number of certifications expiring within 60 days
     *   gaps            : array of per-requirement details
     */
    public function status(VendorPartner $vendor): array
    {
        $requirements = $vendor->requiredCertifications;

        if ($requirements->isEmpty()) {
            return [
                'vendor'          => $this->vendorArray($vendor),
                'status'          => 'compliant',
                'certified_count' => 0,
                'required_count'  => 0,
                'expiring_soon'   => 0,
                'gaps'            => [],
            ];
        }

        $gaps = [];
        $certifiedCount = 0;
        $expiringSoon = 0;

        foreach ($requirements as $item) {
            $required = (int) $item->pivot->required_headcount;

            // Count employees with a completed (passed) training record for this item
            // in the same organisation
            $holders = EmployeeTrainingRecord::where('training_item_id', $item->id)
                ->whereHas('employee', function ($q) use ($vendor) {
                    $q->where('organization_id', $vendor->organization_id);
                })
                ->where('status', 'completed')
                ->where('completion_status', 'passed')
                ->count();

            // Expiring within 60 days (if expiry_date column exists)
            $expiring = EmployeeTrainingRecord::where('training_item_id', $item->id)
                ->whereHas('employee', function ($q) use ($vendor) {
                    $q->where('organization_id', $vendor->organization_id);
                })
                ->where('status', 'completed')
                ->where('completion_status', 'passed')
                ->whereNotNull('expiry_date')
                ->whereBetween('expiry_date', [now(), now()->addDays(60)])
                ->count();

            $expiringSoon += $expiring;
            $met = $holders >= $required;
            if ($met) {
                $certifiedCount++;
            }

            $gaps[] = [
                'training_item_id'   => $item->id,
                'training_item_uuid' => $item->uuid,
                'title'              => $item->title,
                'type'               => $item->type,
                'required_headcount' => $required,
                'actual_holders'     => $holders,
                'expiring_soon'      => $expiring,
                'gap'                => max(0, $required - $holders),
                'met'                => $met,
                'notes'              => $item->pivot->notes,
            ];
        }

        $totalRequired = $requirements->count();
        $unmet = $totalRequired - $certifiedCount;

        $status = match (true) {
            $unmet === 0              => 'compliant',
            $unmet <= ceil($totalRequired * 0.3) => 'at_risk',
            default                   => 'non_compliant',
        };

        return [
            'vendor'          => $this->vendorArray($vendor),
            'status'          => $status,
            'certified_count' => $certifiedCount,
            'required_count'  => $totalRequired,
            'expiring_soon'   => $expiringSoon,
            'gaps'            => $gaps,
        ];
    }

    // ── Bulk assign ───────────────────────────────────────────────────────────

    /**
     * Bulk-create priority-flagged training records for eligible employees
     * who don't yet have a record for the given training item.
     *
     * "Eligible" = active employees in the organisation who have no current
     * (non-withdrawn, non-rejected) record for the item.
     *
     * Returns the number of records created.
     */
    public function bulkAssignForGap(int $organizationId, int $trainingItemId): int
    {
        // Employee IDs who already have an active record for this item
        $existing = EmployeeTrainingRecord::where('organization_id', $organizationId)
            ->where('training_item_id', $trainingItemId)
            ->whereNotIn('status', ['withdrawn', 'rejected'])
            ->pluck('employee_id');

        // Active employees without a record
        $employees = DB::table('employees')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereNotIn('id', $existing)
            ->pluck('id');

        if ($employees->isEmpty()) {
            return 0;
        }

        $now = now();
        $rows = $employees->map(fn($empId) => [
            'organization_id'  => $organizationId,
            'employee_id'      => $empId,
            'training_item_id' => $trainingItemId,
            'status'           => 'not_started',
            'completion_status'=> null,
            'is_priority'      => true,
            'enrollment_date'  => $now->toDateString(),
            'due_date'         => $now->addDays(30)->toDateString(),
            'created_at'       => $now,
            'updated_at'       => $now,
        ])->all();

        EmployeeTrainingRecord::insert($rows);

        return count($rows);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function vendorArray(VendorPartner $vendor): array
    {
        return [
            'id'            => $vendor->id,
            'uuid'          => $vendor->uuid,
            'name'          => $vendor->name,
            'contact_email' => $vendor->contact_email,
            'website_url'   => $vendor->website_url,
            'is_active'     => $vendor->is_active,
        ];
    }
}
