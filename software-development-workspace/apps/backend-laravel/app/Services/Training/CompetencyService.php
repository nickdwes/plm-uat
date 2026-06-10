<?php

namespace App\Services\Training;

use App\Models\Training\CompetencyItem;
use App\Models\Training\RoleCompetencyProfile;
use App\Repositories\Training\CompetencyRepository;
use App\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CompetencyService extends BaseService
{
    public function __construct(CompetencyRepository $repository)
    {
        parent::__construct($repository);
    }

    // ── Competency Items ───────────────────────────────────────────────────────

    public function listCompetencies(int $organizationId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = CompetencyItem::orderBy('category')->orderBy('name');

        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('name', 'ilike', "%{$s}%")->orWhere('description', 'ilike', "%{$s}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function createCompetency(int $organizationId, array $data): CompetencyItem
    {
        $data['organization_id'] = $organizationId;
        return CompetencyItem::create($data);
    }

    public function updateCompetency(CompetencyItem $item, array $data): CompetencyItem
    {
        $item->update($data);
        return $item->fresh();
    }

    public function destroyCompetency(CompetencyItem $item): void
    {
        $item->delete();
    }

    // ── Role Profiles ──────────────────────────────────────────────────────────

    public function listProfiles(int $organizationId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = RoleCompetencyProfile::with(['department', 'competencies'])
            ->orderBy('job_title');

        if (!empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (!empty($filters['search'])) {
            $query->where('job_title', 'ilike', "%{$filters['search']}%");
        }

        return $query->paginate($perPage);
    }

    public function createProfile(int $organizationId, array $data, array $competencies = []): RoleCompetencyProfile
    {
        $data['organization_id'] = $organizationId;
        $profile = RoleCompetencyProfile::create($data);

        if (!empty($competencies)) {
            // $competencies: [{competency_item_id: X, required_level: N}, ...]
            $sync = collect($competencies)->keyBy('competency_item_id')
                ->map(fn($c) => ['required_level' => $c['required_level']])
                ->all();
            $profile->competencies()->sync($sync);
        }

        return $profile->load(['department', 'competencies']);
    }

    public function updateProfile(RoleCompetencyProfile $profile, array $data, ?array $competencies = null): RoleCompetencyProfile
    {
        $profile->update($data);

        if ($competencies !== null) {
            $sync = collect($competencies)->keyBy('competency_item_id')
                ->map(fn($c) => ['required_level' => $c['required_level']])
                ->all();
            $profile->competencies()->sync($sync);
        }

        return $profile->fresh(['department', 'competencies']);
    }

    public function destroyProfile(RoleCompetencyProfile $profile): void
    {
        $profile->delete();
    }
}
