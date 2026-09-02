<?php

namespace App\Services\Training;

use App\Models\Training\TrainingItem;
use App\Repositories\Training\TrainingItemRepository;
use App\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TrainingItemService extends BaseService
{
    public function __construct(TrainingItemRepository $repository)
    {
        parent::__construct($repository);
    }

    public function list(int $organizationId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = TrainingItem::with(['competencies', 'certifyingBody', 'vendorPartner', 'products', 'requiredByVendors'])
            ->withCount('trainingRecords')
            ->orderByDesc('created_at');

        if (isset($filters['is_active'])) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['delivery'])) {
            $query->where('delivery', $filters['delivery']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                  ->orWhere('provider_name', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function create(
        int $organizationId,
        array $data,
        array $competencyIds = [],
        array $productIds = [],
        array $oemCompliance = [],
    ): TrainingItem {
        $data['organization_id'] = $organizationId;
        $data['training_code'] = $this->nextTrainingCode($organizationId);
        $data = $this->normalizeProviderFields($data);
        $item = TrainingItem::create($data);

        if (!empty($competencyIds)) {
            $item->competencies()->sync($competencyIds);
        }
        $item->products()->sync($data['training_provider_type'] === 'vendor_oem' ? $productIds : []);
        $this->syncOemCompliance($item, $oemCompliance);

        return $item->load(['competencies', 'certifyingBody', 'vendorPartner', 'products', 'requiredByVendors']);
    }

    /**
     * A training item names a vendor OR a certifying body, never both. The
     * frontend only shows the field for whichever type is selected, but the
     * API is what actually has to guarantee the other one doesn't linger
     * from a prior edit — e.g. switching an item from "Vendor (OEM Based)"
     * to "Certifying Professional Body" must drop the old vendor and its
     * products, not just hide them.
     */
    private function normalizeProviderFields(array $data): array
    {
        if (($data['training_provider_type'] ?? null) === 'certifying_body') {
            $data['vendor_partner_id'] = null;
        } elseif (($data['training_provider_type'] ?? null) === 'vendor_oem') {
            $data['certifying_body_id'] = null;
        }

        return $data;
    }

    /**
     * The next catalog number for this organisation: TRN-0001.
     *
     * Under an advisory lock, the same reason RequisitionService locks its
     * reference sequence — two people saving a new item at once must not
     * both be handed 0001.
     */
    private function nextTrainingCode(int $organizationId): string
    {
        return DB::transaction(function () use ($organizationId) {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ["training_items:{$organizationId}"]);

            $highest = DB::table('training_items')
                ->where('organization_id', $organizationId)
                ->where('training_code', 'like', 'TRN-%')
                ->selectRaw(
                    "MAX(CASE WHEN training_code ~ '^TRN-[0-9]+$' THEN substring(training_code from '[0-9]+$')::bigint END) AS n",
                )
                ->value('n');

            return 'TRN-' . str_pad((string) ((int) $highest + 1), 4, '0', STR_PAD_LEFT);
        });
    }

    public function update(
        TrainingItem $item,
        array $data,
        ?array $competencyIds = null,
        ?array $productIds = null,
        array $oemCompliance = [],
    ): TrainingItem {
        $data = $this->normalizeProviderFields($data);
        $item->update($data);

        if ($competencyIds !== null) {
            $item->competencies()->sync($competencyIds);
        }

        $providerType = $data['training_provider_type'] ?? $item->training_provider_type;
        if ($providerType === 'vendor_oem') {
            $item->products()->sync($productIds ?? []);
        } else {
            $item->products()->sync([]);
        }
        $this->syncOemCompliance($item, $oemCompliance);

        return $item->fresh(['competencies', 'certifyingBody', 'vendorPartner', 'products', 'requiredByVendors']);
    }

    /**
     * "Required for compliance" is a fact about *this item's own vendor* —
     * an OEM-based item has exactly one, so there is at most one relevant
     * row in vendor_required_certifications for it. sync() with a single
     * vendor id (or an empty array) is therefore always correct: it either
     * writes/refreshes that one row or removes it, and never touches a
     * requirement recorded for a different training item.
     */
    private function syncOemCompliance(TrainingItem $item, array $oemCompliance): void
    {
        $required = $oemCompliance['required'] ?? false;

        if ($item->training_provider_type === 'vendor_oem' && $item->vendor_partner_id && $required) {
            $item->requiredByVendors()->sync([
                $item->vendor_partner_id => [
                    'required_headcount' => $oemCompliance['headcount'] ?? 1,
                    'notes'              => $oemCompliance['notes'] ?? null,
                ],
            ]);
        } else {
            $item->requiredByVendors()->sync([]);
        }
    }

    public function destroy(TrainingItem $item): void
    {
        $item->delete();
    }
}
