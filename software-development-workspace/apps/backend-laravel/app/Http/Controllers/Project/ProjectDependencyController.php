<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectDependency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectDependencyController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'data' => $project->dependencies()
                ->with(['owner:id,first_name,last_name', 'task:id,title', 'dependsOnTask:id,title,status'])
                ->get()->map(fn (ProjectDependency $d) => $this->payload($d)),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $this->validated($request, $project, creating: true);

        $dep = $project->dependencies()->create($data + [
            'organization_id' => $project->organization_id,
        ]);

        return response()->json(['data' => $this->payload(
            $dep->fresh(['owner:id,first_name,last_name', 'task:id,title', 'dependsOnTask:id,title,status'])
        )], 201);
    }

    public function update(Request $request, Project $project, ProjectDependency $dependency): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($dependency->project_id === $project->id, 404);

        $dependency->update($this->validated($request, $project, creating: false));

        return response()->json(['data' => $this->payload(
            $dependency->fresh(['owner:id,first_name,last_name', 'task:id,title', 'dependsOnTask:id,title,status'])
        )]);
    }

    public function destroy(Project $project, ProjectDependency $dependency): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($dependency->project_id === $project->id, 404);

        $dependency->delete();

        return response()->json(['message' => 'Dependency removed.']);
    }

    private function validated(Request $request, Project $project, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title'       => [$req, 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:5000'],
            'kind'        => ['sometimes', Rule::in(ProjectDependency::KINDS)],
            'status'      => ['sometimes', Rule::in(ProjectDependency::STATUSES)],
            'due_on'      => ['nullable', 'date'],
            'owner_employee_id'  => ['nullable', 'integer', 'exists:employees,id'],
            'task_id'            => ['nullable', 'integer', Rule::exists('tasks', 'id')->where('project_id', $project->id)],
            'depends_on_task_id' => ['nullable', 'integer', Rule::exists('tasks', 'id')->where('project_id', $project->id)],
        ]);
    }

    private function payload(ProjectDependency $d): array
    {
        return [
            'id'          => $d->id,
            'title'       => $d->title,
            'description' => $d->description,
            'kind'        => $d->kind,
            'status'      => $d->status,
            'due_on'      => $d->due_on?->toDateString(),
            'task'        => $d->relationLoaded('task') && $d->task ? ['id' => $d->task->id, 'title' => $d->task->title] : null,
            'depends_on_task' => $d->relationLoaded('dependsOnTask') && $d->dependsOnTask
                ? ['id' => $d->dependsOnTask->id, 'title' => $d->dependsOnTask->title, 'status' => $d->dependsOnTask->status] : null,
            'owner'       => $d->relationLoaded('owner') && $d->owner
                ? ['id' => $d->owner->id, 'name' => trim($d->owner->first_name . ' ' . $d->owner->last_name)] : null,
            'created_at'  => $d->created_at?->toDateTimeString(),
        ];
    }
}
