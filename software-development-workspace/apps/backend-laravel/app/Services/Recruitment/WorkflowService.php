<?php

namespace App\Services\Recruitment;

use App\Models\Recruitment\RecruitmentWorkflow;
use App\Models\Recruitment\WorkflowStage;
use App\Repositories\Recruitment\WorkflowRepository;
use App\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class WorkflowService extends BaseService
{
    public function __construct(WorkflowRepository $repository)
    {
        parent::__construct($repository);
    }

    public function list(int $organizationId, int $perPage = 15): LengthAwarePaginator
    {
        return RecruitmentWorkflow::with('stages')
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function create(int $organizationId, array $data): RecruitmentWorkflow
    {
        $data['organization_id'] = $organizationId;

        return DB::transaction(function () use ($data) {
            $stages = $data['stages'] ?? [];
            unset($data['stages']);

            $workflow = RecruitmentWorkflow::create($data);

            if (!empty($stages)) {
                $this->syncStages($workflow, $stages);
            }

            return $workflow->load('stages');
        });
    }

    public function update(RecruitmentWorkflow $workflow, array $data): RecruitmentWorkflow
    {
        return DB::transaction(function () use ($workflow, $data) {
            $stages = $data['stages'] ?? null;
            unset($data['stages']);

            $workflow->update($data);

            if ($stages !== null) {
                $this->syncStages($workflow, $stages);
            }

            return $workflow->fresh(['stages']);
        });
    }

    /**
     * Atomically replace all stages for a workflow.
     * @param array $stages  Array of stage definitions (order determined by array position).
     */
    public function syncStages(RecruitmentWorkflow $workflow, array $stages): void
    {
        $workflow->stages()->delete();

        foreach ($stages as $index => $stage) {
            WorkflowStage::create([
                'workflow_id'    => $workflow->id,
                'name'           => $stage['name'],
                'order_index'    => $index,
                'stage_type'     => $stage['stage_type'] ?? 'interview',
                'coordinator_ids'  => $stage['coordinator_ids'] ?? null,
                'required_actions' => $stage['required_actions'] ?? null,
                'entry_criteria'   => $stage['entry_criteria'] ?? null,
                'exit_criteria'    => $stage['exit_criteria'] ?? null,
            ]);
        }
    }

    public function getDefault(int $organizationId): ?RecruitmentWorkflow
    {
        return RecruitmentWorkflow::with('stages')
            ->where('is_default', true)
            ->first();
    }

    public function delete(RecruitmentWorkflow $workflow): void
    {
        $workflow->delete();
    }
}
