<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectTrainee;
use App\Models\Project\ProjectTrainingFulfillment;
use App\Models\Training\TrainingItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Training & enablement — where a training order is activated: the course from
 * the catalogue, the cost per person, and the register of who is being trained
 * (internal staff or the customer's people) with each one's travel.
 */
class ProjectTrainingController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($this->payload($project));
    }

    public function updateFulfillment(Request $request, Project $project, ProjectTrainingFulfillment $fulfillment): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($fulfillment->project_id === $project->id, 404);

        $data = $request->validate([
            'training_item_id' => ['sometimes', 'nullable', 'integer', Rule::exists('training_items', 'id')->where('organization_id', $project->organization_id)],
            'title'           => ['sometimes', 'string', 'max:200'],
            'cost_per_person' => ['sometimes', 'numeric', 'min:0', 'max:9999999999999'],
            'currency'        => ['sometimes', 'string', 'size:3'],
            'mode'            => ['sometimes', 'nullable', Rule::in(ProjectTrainingFulfillment::MODES)],
            'status'          => ['sometimes', Rule::in(ProjectTrainingFulfillment::STATUSES)],
            'notes'           => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        // Picking a course pulls its name and (if none typed yet) cost across.
        // Delivery is left to the fulfilment's own mode vocabulary.
        if (array_key_exists('training_item_id', $data) && $data['training_item_id']) {
            $item = TrainingItem::find($data['training_item_id']);
            if ($item) {
                $data['title'] ??= $item->title;
                if (((float) $fulfillment->cost_per_person <= 0) && $item->estimated_cost) {
                    $data['cost_per_person'] ??= (float) $item->estimated_cost;
                }
            }
        }

        $fulfillment->update($data);

        return response()->json($this->payload($project));
    }

    public function storeTrainee(Request $request, Project $project, ProjectTrainingFulfillment $fulfillment): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($fulfillment->project_id === $project->id, 404);

        $data = $this->validateTrainee($request, $project);

        // An internal trainee named by employee inherits that employee's name.
        if (empty($data['name']) && ! empty($data['employee_id'])) {
            $emp = \App\Models\Employee::find($data['employee_id']);
            $data['name'] = $emp ? trim($emp->first_name . ' ' . $emp->last_name) : 'Trainee';
        }

        $fulfillment->trainees()->create($data + ['organization_id' => $project->organization_id]);

        return response()->json($this->payload($project));
    }

    public function updateTrainee(Request $request, Project $project, ProjectTrainingFulfillment $fulfillment, ProjectTrainee $trainee): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($fulfillment->project_id === $project->id && $trainee->project_training_fulfillment_id === $fulfillment->id, 404);

        $trainee->update($this->validateTrainee($request, $project, creating: false));

        return response()->json($this->payload($project));
    }

    public function destroyTrainee(Project $project, ProjectTrainingFulfillment $fulfillment, ProjectTrainee $trainee): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($fulfillment->project_id === $project->id && $trainee->project_training_fulfillment_id === $fulfillment->id, 404);

        $trainee->delete();

        return response()->json($this->payload($project));
    }

    private function validateTrainee(Request $request, Project $project, bool $creating = true): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'party'       => ['sometimes', Rule::in(ProjectTrainee::PARTIES)],
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('primary_organization_id', $project->organization_id)],
            'name'        => [$creating ? 'required_without:employee_id' : 'sometimes', 'nullable', 'string', 'max:160'],
            'email'       => ['nullable', 'email', 'max:160'],
            'designation' => ['nullable', 'string', 'max:160'],
            'needs_travel'=> ['sometimes', 'boolean'],
            'travel_location' => ['nullable', 'string', 'max:160'],
            'travel_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'notes'       => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function payload(Project $project): array
    {
        $fulfillments = $project->trainingFulfillments()
            ->with(['course:id,title,training_code,delivery', 'trainees.employee:id,first_name,last_name', 'order:id,reference'])
            ->get();

        return [
            'data' => $fulfillments->map(function (ProjectTrainingFulfillment $f) {
                $trainees = $f->trainees;
                $travel = (float) $trainees->sum(fn ($t) => (float) $t->travel_cost);

                return [
                    'id'              => $f->id,
                    'order_reference' => $f->order?->reference,
                    'title'           => $f->title,
                    'training_item_id'=> $f->training_item_id,
                    'course'          => $f->course ? ['id' => $f->course->id, 'title' => $f->course->title, 'code' => $f->course->training_code] : null,
                    'cost_per_person' => (float) $f->cost_per_person,
                    'currency'        => strtoupper($f->currency),
                    'mode'            => $f->mode,
                    'status'          => $f->status,
                    'notes'           => $f->notes,
                    'trainees'        => $trainees->map(fn (ProjectTrainee $t) => [
                        'id'          => $t->id,
                        'party'       => $t->party,
                        'employee_id' => $t->employee_id,
                        'name'        => $t->name,
                        'email'       => $t->email,
                        'designation' => $t->designation,
                        'needs_travel'=> $t->needs_travel,
                        'travel_location' => $t->travel_location,
                        'travel_cost' => $t->travel_cost !== null ? (float) $t->travel_cost : null,
                    ])->values(),
                    'trainee_count' => $trainees->count(),
                    'travel_total'  => round($travel, 2),
                    'total_cost'    => round((float) $f->cost_per_person * $trainees->count() + $travel, 2),
                ];
            })->values(),
            'catalog' => TrainingItem::where('is_active', true)->orderBy('title')
                ->get(['id', 'title', 'training_code', 'delivery', 'estimated_cost', 'cost_currency'])
                ->map(fn ($i) => [
                    'id' => $i->id, 'title' => $i->title, 'code' => $i->training_code,
                    'delivery' => $i->delivery, 'cost' => $i->estimated_cost !== null ? (float) $i->estimated_cost : null,
                ]),
            'options' => [
                'modes'    => ProjectTrainingFulfillment::MODES,
                'statuses' => ProjectTrainingFulfillment::STATUSES,
                'parties'  => ProjectTrainee::PARTIES,
            ],
        ];
    }
}
