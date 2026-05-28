<?php

namespace App\Services\Recruitment;

use App\Models\Recruitment\VacancyTemplate;
use App\Repositories\Recruitment\VacancyTemplateRepository;
use App\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class VacancyTemplateService extends BaseService
{
    public function __construct(VacancyTemplateRepository $repository)
    {
        parent::__construct($repository);
    }

    public function list(int $organizationId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = VacancyTemplate::with(['workflow.stages', 'department', 'designation'])
            ->orderBy('name');

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('job_title', 'ilike', "%{$search}%");
            });
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return $query->paginate($perPage);
    }

    public function create(int $organizationId, array $data): VacancyTemplate
    {
        $data['organization_id'] = $organizationId;
        return VacancyTemplate::create($data);
    }

    public function update(VacancyTemplate $template, array $data): VacancyTemplate
    {
        $template->update($data);
        return $template->fresh(['workflow.stages', 'department', 'designation']);
    }

    public function delete(VacancyTemplate $template): void
    {
        $template->delete();
    }

    public function duplicate(VacancyTemplate $template): VacancyTemplate
    {
        $attrs = $template->toArray();
        unset($attrs['id'], $attrs['created_at'], $attrs['updated_at'], $attrs['deleted_at']);
        $attrs['name'] = $attrs['name'] . ' (Copy)';
        return VacancyTemplate::create($attrs);
    }
}
