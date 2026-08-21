<?php

namespace App\Services\Recruitment;

use App\Models\Recruitment\Vacancy;
use App\Models\Recruitment\VacancyTemplate;
use App\Repositories\Recruitment\VacancyRepository;
use App\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class VacancyService extends BaseService
{
    public function __construct(VacancyRepository $repository)
    {
        parent::__construct($repository);
    }

    public function list(int $organizationId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Vacancy::with(['department', 'designation', 'hiringManager', 'workflow.stages'])
            ->orderByDesc('created_at');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('job_title', 'ilike', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function create(int $organizationId, array $data): Vacancy
    {
        $data['organization_id'] = $organizationId;

        // Auto-copy form schema from template if not overridden
        if (!empty($data['template_id']) && empty($data['application_form_schema'])) {
            $template = VacancyTemplate::find($data['template_id']);
            if ($template) {
                $data['application_form_schema'] = $template->application_form_schema;
                // Auto-attach workflow from template if none specified
                if (empty($data['workflow_id']) && $template->workflow_id) {
                    $data['workflow_id'] = $template->workflow_id;
                }
            }
        }

        /*
         * A vacancy raised from a requisition inherits the skills it asked for.
         *
         * Copied at this moment rather than read through on demand: the
         * requisition can be edited or withdrawn afterwards, and a vacancy
         * whose requirements shifted under applicants who had already answered
         * would be judging them against a bar they never saw.
         */
        if (! empty($data['talent_requisition_id']) && empty($data['required_skills'])) {
            $data['required_skills'] = \App\Models\Recruitment\TalentRequisition::whereKey(
                $data['talent_requisition_id'],
            )->value('skills');
        }

        $vacancy = Vacancy::create($data);
        return $vacancy->load(['department', 'designation', 'hiringManager', 'workflow.stages']);
    }

    public function update(Vacancy $vacancy, array $data): Vacancy
    {
        $vacancy->update($data);
        return $vacancy->fresh(['department', 'designation', 'hiringManager', 'workflow.stages']);
    }

    public function close(Vacancy $vacancy): Vacancy
    {
        $vacancy->update(['status' => 'closed']);
        return $vacancy->fresh();
    }

    public function getByUuid(string $uuid): ?Vacancy
    {
        return Vacancy::with(['workflow.stages', 'department', 'designation'])
            ->where('uuid', $uuid)
            ->where('status', 'open')
            ->first();
    }
}
