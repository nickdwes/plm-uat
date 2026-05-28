<?php

namespace App\Services\Recruitment;

use App\Models\Recruitment\Application;
use App\Models\Recruitment\ApplicationStageHistory;
use App\Models\Recruitment\RecruitmentNotification;
use App\Models\Recruitment\Vacancy;
use App\Models\Recruitment\WorkflowStage;
use App\Repositories\Recruitment\ApplicationRepository;
use App\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationService extends BaseService
{
    public function __construct(
        ApplicationRepository $repository,
        private readonly ScreeningService $screeningService,
    ) {
        parent::__construct($repository);
    }

    public function listForVacancy(Vacancy $vacancy, array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $query = Application::with(['candidate', 'currentStage'])
            ->where('vacancy_id', $vacancy->id)
            ->orderByDesc('applied_at');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['stage_id'])) {
            $query->where('current_stage_id', $filters['stage_id']);
        }

        return $query->paginate($perPage);
    }

    public function create(Vacancy $vacancy, array $data): Application
    {
        return DB::transaction(function () use ($vacancy, $data) {
            // Assign first stage of the vacancy workflow
            $firstStage = $vacancy->workflow->stages()->orderBy('order_index')->first();

            $application = Application::create([
                'vacancy_id'       => $vacancy->id,
                'candidate_id'     => $data['candidate_id'],
                'current_stage_id' => $firstStage?->id,
                'status'           => 'active',
                'form_data'        => $data['form_data'] ?? null,
                'applied_at'       => now(),
            ]);

            if ($firstStage) {
                ApplicationStageHistory::create([
                    'application_id' => $application->id,
                    'from_stage_id'  => null,
                    'to_stage_id'    => $firstStage->id,
                    'moved_by'       => $data['created_by'],
                    'moved_at'       => now(),
                ]);
            }

            // Run auto-screening
            $score = $this->screeningService->evaluate($application, $vacancy);
            if ($score !== null) {
                $application->update(['screening_score' => $score]);
            }

            $this->recordNotification($application, 'new_application');

            return $application->load(['candidate', 'currentStage', 'stageHistory.toStage', 'stageHistory.movedBy']);
        });
    }

    /**
     * Move an application to a new workflow stage.
     */
    public function moveStage(Application $application, int $toStageId, int $movedBy, ?string $notes = null): Application
    {
        return DB::transaction(function () use ($application, $toStageId, $movedBy, $notes) {
            // Verify the target stage belongs to the vacancy's workflow
            $vacancy = $application->vacancy()->with('workflow')->first();
            $stageIds = $vacancy->workflow->stages->pluck('id')->toArray();

            if (!in_array($toStageId, $stageIds, true)) {
                throw ValidationException::withMessages([
                    'stage_id' => 'Stage does not belong to this vacancy\'s workflow.',
                ]);
            }

            $fromStageId = $application->current_stage_id;

            ApplicationStageHistory::create([
                'application_id' => $application->id,
                'from_stage_id'  => $fromStageId,
                'to_stage_id'    => $toStageId,
                'moved_by'       => $movedBy,
                'notes'          => $notes,
                'moved_at'       => now(),
            ]);

            $toStage = WorkflowStage::findOrFail($toStageId);

            $newStatus = match($toStage->stage_type) {
                'hired'    => 'hired',
                'rejected' => 'rejected',
                'offer'    => 'shortlisted',
                default    => $application->status,
            };

            $application->update([
                'current_stage_id' => $toStageId,
                'status'           => $newStatus,
            ]);

            $this->recordNotification($application->fresh(), 'stage_change');

            return $application->fresh(['candidate', 'currentStage', 'stageHistory.toStage', 'stageHistory.movedBy']);
        });
    }

    public function bulkAction(array $applicationIds, string $action, int $movedBy): int
    {
        $count = 0;
        foreach ($applicationIds as $id) {
            $app = Application::find($id);
            if (!$app) continue;

            if ($action === 'reject') {
                $rejectedStage = WorkflowStage::where('workflow_id', $app->vacancy->workflow_id)
                    ->where('stage_type', 'rejected')
                    ->first();

                if ($rejectedStage) {
                    $this->moveStage($app, $rejectedStage->id, $movedBy, 'Bulk rejected');
                } else {
                    $app->update(['status' => 'rejected']);
                }
                $count++;
            } elseif ($action === 'shortlist') {
                $app->update(['status' => 'shortlisted']);
                $count++;
            }
        }
        return $count;
    }

    private function recordNotification(Application $application, string $eventType): void
    {
        try {
            RecruitmentNotification::create([
                'organization_id' => $application->vacancy->organization_id,
                'application_id'  => $application->id,
                'event_type'      => $eventType,
                'payload'         => [
                    'candidate_name' => $application->candidate->full_name,
                    'vacancy_name'   => $application->vacancy->name,
                    'stage'          => $application->currentStage?->name,
                ],
            ]);
        } catch (\Throwable) {
            // Notifications are best-effort; don't block the main flow
        }
    }
}
