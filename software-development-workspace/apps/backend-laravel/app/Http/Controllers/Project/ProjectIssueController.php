<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectIssue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectIssueController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'data' => $project->issues()
                ->with(['raisedBy:id,name', 'escalatedTo:id,first_name,last_name', 'task:id,title'])
                ->get()->map(fn (ProjectIssue $i) => $this->payload($i)),
            // Issues raised on the project's tasks, surfaced here read-only —
            // they belong to and are resolved on the task, but the project's
            // Issues tab is where a PM expects to see everything in the way.
            'task_issues' => $this->taskIssues($project),
        ]);
    }

    /** Task-level issues (not risks) across the project's tasks. */
    private function taskIssues(Project $project): array
    {
        return \App\Models\Task\TaskIssue::whereHas('task', fn ($q) => $q->where('project_id', $project->id))
            ->where('kind', 'issue')
            ->with(['task:id,title,reference', 'raiser:id,first_name,last_name'])
            ->latest('id')->get()
            ->map(fn (\App\Models\Task\TaskIssue $ti) => [
                'id'        => $ti->id,
                'title'     => $ti->name,
                'details'   => $ti->details,
                'priority'  => $ti->priority,
                'status'    => $ti->status,
                'task'      => $ti->task ? ['id' => $ti->task->id, 'title' => $ti->task->title, 'reference' => $ti->task->reference] : null,
                'raised_by' => $ti->raiser ? trim($ti->raiser->first_name . ' ' . $ti->raiser->last_name) : null,
                'resolved_at' => $ti->resolved_at?->toDateTimeString(),
                'created_at'  => $ti->created_at?->toDateTimeString(),
            ])->values()->all();
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'title'       => ['required', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:5000'],
            'severity'    => ['nullable', Rule::in(ProjectIssue::SEVERITIES)],
            'task_id'     => ['nullable', 'integer', 'exists:tasks,id'],
        ]);

        $issue = $project->issues()->create($data + [
            'organization_id' => $project->organization_id,
            'status'          => ProjectIssue::STATUS_OPEN,
            'severity'        => $data['severity'] ?? 'medium',
            'raised_by'       => $request->user()->id,
        ]);

        return response()->json(['data' => $this->payload($issue->fresh(['raisedBy:id,name']))], 201);
    }

    public function update(Request $request, Project $project, ProjectIssue $issue): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($issue->project_id === $project->id, 404);

        $data = $request->validate([
            'title'       => ['sometimes', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:5000'],
            'severity'    => ['sometimes', Rule::in(ProjectIssue::SEVERITIES)],
            'status'      => ['sometimes', Rule::in(ProjectIssue::STATUSES)],
        ]);

        // Resolving stamps who and when; reopening clears it.
        if (($data['status'] ?? null) === ProjectIssue::STATUS_RESOLVED && $issue->resolved_at === null) {
            $data['resolved_at'] = now();
            $data['resolved_by'] = $request->user()->id;
        } elseif (isset($data['status']) && $data['status'] !== ProjectIssue::STATUS_RESOLVED) {
            $data['resolved_at'] = null;
            $data['resolved_by'] = null;
        }

        $issue->update($data);

        return response()->json(['data' => $this->payload($issue->fresh(['raisedBy:id,name', 'escalatedTo:id,first_name,last_name']))]);
    }

    public function escalate(Request $request, Project $project, ProjectIssue $issue): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($issue->project_id === $project->id, 404);

        $data = $request->validate([
            'escalated_to' => ['required', 'integer', 'exists:employees,id'],
            'reason'       => ['nullable', 'string', 'max:500'],
        ]);

        $issue->update([
            'escalated_at'      => now(),
            'escalated_to'      => $data['escalated_to'],
            'escalation_reason' => $data['reason'] ?? null,
        ]);

        return response()->json(['data' => $this->payload($issue->fresh(['raisedBy:id,name', 'escalatedTo:id,first_name,last_name']))]);
    }

    public function destroy(Project $project, ProjectIssue $issue): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($issue->project_id === $project->id, 404);

        $issue->delete();

        return response()->json(['message' => 'Issue removed.']);
    }

    private function payload(ProjectIssue $i): array
    {
        return [
            'id'          => $i->id,
            'title'       => $i->title,
            'description' => $i->description,
            'status'      => $i->status,
            'severity'    => $i->severity,
            'task'        => $i->relationLoaded('task') && $i->task ? ['id' => $i->task->id, 'title' => $i->task->title] : null,
            'raised_by'   => $i->relationLoaded('raisedBy') ? $i->raisedBy?->name : null,
            'escalated'   => $i->escalated_at !== null,
            'escalated_to' => $i->relationLoaded('escalatedTo') && $i->escalatedTo
                ? trim($i->escalatedTo->first_name . ' ' . $i->escalatedTo->last_name) : null,
            'escalation_reason' => $i->escalation_reason,
            'resolved_at' => $i->resolved_at?->toDateTimeString(),
            'created_at'  => $i->created_at?->toDateTimeString(),
        ];
    }
}
