<?php

namespace App\Services\Project;

use App\Models\Employee;
use App\Models\Project\ProjectTravelRequest;
use App\Models\Task\TaskMember;
use App\Models\Travel\TravelDestination;
use App\Models\Travel\TravelExpenseCategory;
use App\Models\Travel\TravelRequest;
use App\Services\Travel\TravelCostingService;
use App\Services\Travel\TravelRequestService;

/**
 * Raises a project's planned travel cycle into the corporate travel module.
 *
 * A project cycle is multi-person; the module is per-traveller — so one cycle
 * becomes one module request per traveller (the assignees of the cycle's
 * tasks), each carrying the project it serves so the module can list and filter
 * by project. Idempotent: a cycle already raised is not raised again.
 */
class ProjectTravelBridge
{
    public function __construct(
        private TravelRequestService $service,
        private TravelCostingService $costing,
    ) {
    }

    /**
     * @return array{raised:int, submitted:int, existing:int, no_travellers:bool}
     */
    public function raise(ProjectTravelRequest $ptr, ?int $actorId = null): array
    {
        $existing = TravelRequest::where('source_project_travel_request_id', $ptr->id)->count();
        if ($existing > 0) {
            return ['raised' => 0, 'submitted' => 0, 'existing' => $existing, 'no_travellers' => false];
        }

        $travellers = $this->travellers($ptr);
        if ($travellers->isEmpty()) {
            return ['raised' => 0, 'submitted' => 0, 'existing' => 0, 'no_travellers' => true];
        }

        $project = $ptr->project;
        $destination = $this->destination($ptr->organization_id, $ptr->location);
        $travelType = $destination?->is_international ? 'international' : 'local';
        $departure = ($ptr->start_date ?? now())->toDateString();
        $return = ($ptr->end_date ?? $ptr->start_date ?? now())->toDateString();

        $raised = 0;
        $submitted = 0;
        foreach ($travellers as $employeeId) {
            try {
                $request = $this->service->create([
                    'employee_id'           => $employeeId,
                    'account_id'            => $project?->account_id,
                    'travel_destination_id' => $destination?->id,
                    'travel_mode'           => $this->modeLabel($ptr->mode),
                    'travel_type'           => $travelType,
                    'departure_date'        => $departure,
                    'return_date'           => $return,
                    'accommodation_needed'  => (bool) $ptr->accommodation,
                    'purpose'               => trim("Project travel — {$ptr->location}" . ($project ? " ({$project->name})" : '')),
                    'business_impact'       => 'Project delivery travel',
                    'currency'              => strtoupper($ptr->currency ?: 'KES'),
                ], $ptr->organization_id, $actorId);
            } catch (\Throwable) {
                // A traveller who is not a member of this org is skipped, not fatal.
                continue;
            }

            $request->update([
                'project_id'                       => $ptr->project_id,
                'source_project_travel_request_id' => $ptr->id,
                'linked_task_ref'                  => "PRJ-{$ptr->project_id}",
            ]);
            $raised++;

            // Take it down the same journey a traveller would: rate-card
            // allowances + a transport leg, then submit into approval.
            $this->buildJourney($request->fresh(), $ptr, (int) $employeeId, $destination, $departure);
            if ($this->trySubmit($request->fresh())) {
                $submitted++;
            }
        }

        return ['raised' => $raised, 'submitted' => $submitted, 'existing' => 0, 'no_travellers' => false];
    }

    /**
     * Build the request the module's own way — one allowance line per priced
     * expense category (per-diem, accommodation, incidentals…) at the policy
     * rate for the traveller's grade and destination, plus a transport leg.
     */
    private function buildJourney(TravelRequest $request, ProjectTravelRequest $ptr, int $employeeId, ?TravelDestination $destination, string $onDate): void
    {
        $jobGradeId = Employee::find($employeeId)?->job_grade_id;
        $days = max(1, (int) $request->days);

        foreach (TravelExpenseCategory::where('organization_id', $ptr->organization_id)->where('is_active', true)->get() as $category) {
            $rate = $this->costing->rateFor($ptr->organization_id, $category->id, $jobGradeId, $destination?->id, $onDate);
            if (! $rate) {
                continue;   // an unpriced category is not claimed automatically
            }

            $qty = $rate->unit === 'per_trip' ? 1 : $days;
            try {
                $this->service->addItem($request, [
                    'travel_expense_category_id' => $category->id,
                    'description'                => $category->name,
                    'quantity'                   => $qty,
                    'unit'                       => $rate->unit,
                    'amount_claimed'             => round((float) $rate->amount * $qty, 2),
                ]);
            } catch (\Throwable) {
                // Skip a category the module refuses; the rest still land.
            }
        }

        try {
            $this->service->addLeg($request->fresh(), [
                'mode'          => 'flight',
                'travel_type'   => $request->travel_type,
                'from_location' => 'Base',
                'to_location'   => $ptr->location ?: 'Site',
                'purpose'       => 'Return flight',
            ]);
        } catch (\Throwable) {
            // A leg is optional — allowances alone are enough to submit.
        }

        $this->addItinerary($request, $ptr);
    }

    /**
     * The itinerary is the work the trip exists for. It expands the cycle's
     * tasks into the actual work packages — each task and its subtasks — on the
     * day each runs, labelled by the phase it belongs to (a subphase reads as
     * its own phase). So a single task with subtasks, a group of tasks, or a
     * whole phase all land as a readable day-by-day plan; the travel dates stay
     * the request's own departure/return.
     */
    private function addItinerary(TravelRequest $request, ProjectTravelRequest $ptr): void
    {
        $taskIds = collect($ptr->tasks ?? [])->pluck('id')->filter()->all();
        if (! $taskIds) {
            return;
        }

        $tasks = \App\Models\Task\Task::whereIn('id', $taskIds)
            ->with(['subtasks' => fn ($q) => $q->orderBy('started_on')->orderBy('position')])
            ->get()
            ->sortBy(fn ($t) => $t->started_on?->toDateString() ?? '9999');

        // Phase names for every task and subtask, so lines carry their phase.
        $phaseIds = $tasks->pluck('phase_id')
            ->merge($tasks->flatMap->subtasks->pluck('phase_id'))->filter()->unique();
        $phases = \App\Models\Project\ProjectPhase::whereIn('id', $phaseIds)->pluck('name', 'id');

        $fallback = $request->departure_date?->toDateString() ?? now()->toDateString();
        $position = 0;

        foreach ($tasks as $task) {
            $this->itineraryLine($request, $task, $phases, $fallback, false, $position);
            foreach ($task->subtasks as $subtask) {
                $this->itineraryLine($request, $subtask, $phases, $fallback, true, $position);
            }
        }
    }

    private function itineraryLine(TravelRequest $request, \App\Models\Task\Task $task, $phases, string $fallback, bool $isSub, int &$position): void
    {
        $request->itinerary()->create([
            'starts_on' => $task->started_on?->toDateString() ?? $fallback,
            'ends_on'   => $task->deadline?->toDateString(),
            'activity'  => ($isSub ? '↳ ' : '') . trim(($task->reference ? "{$task->reference} · " : '') . $task->title),
            'category'  => $phases[$task->phase_id] ?? ($isSub ? 'Subtask' : 'Task'),
            'position'  => $position++,
        ]);
    }

    /** Submit into approval; a stageless org just leaves it a costed draft. */
    private function trySubmit(TravelRequest $request): bool
    {
        try {
            $this->service->submit($request);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** The assignees of the cycle's tasks, unique — the people who travel. */
    private function travellers(ProjectTravelRequest $ptr): \Illuminate\Support\Collection
    {
        $taskIds = collect($ptr->tasks ?? [])->pluck('id')->filter()->all();
        if (! $taskIds) {
            return collect();
        }

        $assignees = TaskMember::whereIn('task_id', $taskIds)
            ->where('capacity', 'assignee')->whereNotNull('employee_id')
            ->pluck('employee_id')->unique()->values();

        // Fall back to anyone on the tasks if no one is a named assignee.
        return $assignees->isNotEmpty() ? $assignees
            : TaskMember::whereIn('task_id', $taskIds)->whereNotNull('employee_id')->pluck('employee_id')->unique()->values();
    }

    private function destination(int $orgId, ?string $location): ?TravelDestination
    {
        if (! $location) {
            return null;
        }

        return TravelDestination::where('organization_id', $orgId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($location))])->first();
    }

    /** Map the cycle's mode key to the module's free-text mode. */
    private function modeLabel(?string $mode): string
    {
        return match ($mode) {
            'air'        => 'Air',
            'inter_city' => 'Inter-city',
            'road'       => 'Road',
            'local'      => 'Local',
            default      => 'Air',
        };
    }
}
