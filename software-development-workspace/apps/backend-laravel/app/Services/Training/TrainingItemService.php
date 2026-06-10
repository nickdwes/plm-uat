<?php

namespace App\Services\Training;

use App\Models\Training\TrainingItem;
use App\Repositories\Training\TrainingItemRepository;
use App\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TrainingItemService extends BaseService
{
    public function __construct(TrainingItemRepository $repository)
    {
        parent::__construct($repository);
    }

    public function list(int $organizationId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = TrainingItem::with(['competencies'])
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

    public function create(int $organizationId, array $data, array $competencyIds = []): TrainingItem
    {
        $data['organization_id'] = $organizationId;
        $item = TrainingItem::create($data);

        if (!empty($competencyIds)) {
            $item->competencies()->sync($competencyIds);
        }

        return $item->load('competencies');
    }

    public function update(TrainingItem $item, array $data, ?array $competencyIds = null): TrainingItem
    {
        $item->update($data);

        if ($competencyIds !== null) {
            $item->competencies()->sync($competencyIds);
        }

        return $item->fresh(['competencies']);
    }

    public function destroy(TrainingItem $item): void
    {
        $item->delete();
    }
}
