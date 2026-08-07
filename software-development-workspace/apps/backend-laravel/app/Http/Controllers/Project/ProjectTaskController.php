<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectAttachment;
use App\Models\Project\ProjectComment;
use App\Models\Project\ProjectTask;
use App\Models\Project\ProjectTaskMember;
use App\Support\Account\ReferenceSequence;
use App\Services\Project\ProjectActivityService;
use App\Support\Storage\DocumentVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tasks, and the things the task drawer hangs off them.
 *
 * Authorised against the project rather than the task: a task has no meaning
 * apart from its project, and a second permission family for it would be one
 * more thing nobody assigns.
 */
class ProjectTaskController extends Controller
{
    public function __construct(private readonly ProjectActivityService $activities) {}

    private const DETAIL = ['members.employee', 'subtasks.members.employee', 'dependencies', 'attachments'];

    // ── Tasks ─────────────────────────────────────────────────────────────

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $tasks = $project->rootTasks()
            ->with(['members.employee', 'subtasks.members.employee', 'dependencies'])
            ->get();

        return response()->json(['data' => $tasks->map(fn ($t) => $this->payload($t))]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $this->rules($request, $project);

        $task = $project->tasks()->create($data + [
            'reference'       => ReferenceSequence::next('TK', 'project_tasks', $project->organization_id),
            'organization_id' => $project->organization_id,
            // Appended rather than inserted: a new task belongs at the end
            // until somebody moves it.
            'position'        => (int) $project->tasks()->max('position') + 1,
            'created_by'      => $request->user()->id,
        ]);

        $this->activities->record(
            $project, 'task', "Added “{$task->title}”", $task,
            $request->user()->id, $request->user()->employeeId(),
        );

        return response()->json(['data' => $this->payload($task->load(self::DETAIL))], 201);
    }

    public function show(Project $project, ProjectTask $task): JsonResponse
    {
        $this->authorize('view', $project);
        abort_unless($task->project_id === $project->id, 404);

        return response()->json([
            'data' => $this->payload($task->load(array_merge(self::DETAIL, ['comments.replies.employee', 'comments.employee']))),
        ]);
    }

    public function update(Request $request, Project $project, ProjectTask $task): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($task->project_id === $project->id, 404);

        $data = $this->rules($request, $project, $task);

        /*
         * Completing stamps the time; reopening clears it. A completed_at left
         * on a reopened task is the same lie as a delivery date on a reopened
         * project — it would report finished work that is back in progress.
         */
        if (($data['status'] ?? $task->status) === ProjectTask::DONE) {
            $data['completed_at'] = $task->completed_at ?? now();
        } elseif (array_key_exists('status', $data)) {
            $data['completed_at'] = null;
        }

        $was = $task->status;
        $task->update($data);

        $this->activities->taskChanged(
            $project, $task->fresh(), $was,
            $request->user()->id, $request->user()->employeeId(),
        );

        return response()->json(['data' => $this->payload($task->fresh(self::DETAIL))]);
    }

    public function destroy(Project $project, ProjectTask $task): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($task->project_id === $project->id, 404);

        $task->delete();

        return response()->json(['message' => 'Task removed.']);
    }

    /** Reordering, which is one gesture and should not resend the task. */
    public function reorder(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'order'   => ['required', 'array'],
            'order.*' => ['integer', Rule::exists('project_tasks', 'id')->where('project_id', $project->id)],
        ]);

        foreach ($data['order'] as $position => $id) {
            ProjectTask::where('id', $id)->update(['position' => $position]);
        }

        return $this->index($request, $project);
    }

    // ── Members ───────────────────────────────────────────────────────────

    public function storeMember(Request $request, Project $project, ProjectTask $task): JsonResponse
    {
        $this->authorize('assign', $project);
        abort_unless($task->project_id === $project->id, 404);

        $data = $request->validate([
            'employee_id' => ['required', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $project->organization_id)],
            'capacity' => ['sometimes', Rule::in(ProjectTaskMember::CAPACITIES)],
        ]);

        $task->members()->firstOrCreate([
            'employee_id' => $data['employee_id'],
            'capacity'    => $data['capacity'] ?? 'assignee',
        ], ['organization_id' => $project->organization_id]);

        return response()->json(['data' => $this->payload($task->fresh(self::DETAIL))]);
    }

    public function destroyMember(Project $project, ProjectTask $task, ProjectTaskMember $member): JsonResponse
    {
        $this->authorize('assign', $project);
        abort_unless($task->project_id === $project->id && $member->project_task_id === $task->id, 404);

        $member->delete();

        return response()->json(['data' => $this->payload($task->fresh(self::DETAIL))]);
    }

    // ── Dependencies ──────────────────────────────────────────────────────

    public function storeDependency(Request $request, Project $project, ProjectTask $task): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($task->project_id === $project->id, 404);

        $data = $request->validate([
            // Scoped to this project: a task cannot wait on somebody else's.
            'depends_on_task_id' => ['required', 'integer',
                Rule::exists('project_tasks', 'id')->where('project_id', $project->id)],
        ]);

        if ((int) $data['depends_on_task_id'] === $task->id) {
            throw ValidationException::withMessages([
                'depends_on_task_id' => 'A task cannot wait on itself.',
            ]);
        }

        // A cycle is a deadlock nothing would ever surface, so it is refused
        // rather than recorded.
        if ($this->wouldCycle($task->id, (int) $data['depends_on_task_id'])) {
            throw ValidationException::withMessages([
                'depends_on_task_id' => 'That would make the two tasks wait on each other.',
            ]);
        }

        $task->dependencies()->syncWithoutDetaching([
            $data['depends_on_task_id'] => ['organization_id' => $project->organization_id],
        ]);

        return response()->json(['data' => $this->payload($task->fresh(self::DETAIL))]);
    }

    public function destroyDependency(Project $project, ProjectTask $task, ProjectTask $dependency): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($task->project_id === $project->id, 404);

        $task->dependencies()->detach($dependency->id);

        return response()->json(['data' => $this->payload($task->fresh(self::DETAIL))]);
    }

    /** Walks the chain from the proposed prerequisite back to this task. */
    private function wouldCycle(int $taskId, int $prerequisiteId, array $seen = []): bool
    {
        if ($prerequisiteId === $taskId) {
            return true;
        }

        if (in_array($prerequisiteId, $seen, true)) {
            return false;   // already visited; not a route back to us
        }

        $seen[] = $prerequisiteId;

        foreach (ProjectTask::find($prerequisiteId)?->dependencies()->pluck('depends_on_task_id') ?? [] as $next) {
            if ($this->wouldCycle($taskId, (int) $next, $seen)) {
                return true;
            }
        }

        return false;
    }

    // ── Comments ──────────────────────────────────────────────────────────

    public function storeComment(Request $request, Project $project): JsonResponse
    {
        // Commenting is not editing: anybody who can see the project should be
        // able to say something about it.
        $this->authorize('view', $project);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'project_task_id' => ['nullable', 'integer',
                Rule::exists('project_tasks', 'id')->where('project_id', $project->id)],
            'parent_id' => ['nullable', 'integer',
                Rule::exists('project_comments', 'id')->where('project_id', $project->id)],
        ]);

        // Threading is capped at one level: a reply to a reply attaches to the
        // top of that thread rather than nesting further.
        if (! empty($data['parent_id'])) {
            $parent = ProjectComment::find($data['parent_id']);
            $data['parent_id'] = $parent?->parent_id ?? $data['parent_id'];
        }

        $project->comments()->getModel()->create($data + [
            'organization_id' => $project->organization_id,
            'project_id'      => $project->id,
            'employee_id'     => $request->user()->employeeId(),
            'created_by'      => $request->user()->id,
        ]);

        $this->activities->record(
            $project, 'comment', 'Commented on the project', null,
            $request->user()->id, $request->user()->employeeId(),
        );

        return $this->comments($project);
    }

    /**
     * The project's timeline.
     *
     * Written by the application, not typed — a feed nobody has to remember to
     * update is the only kind worth having.
     */
    public function activities(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $entries = $project->activities()->with(['employee', 'author'])->limit(200)->get();

        return response()->json([
            'data' => $entries->map(fn ($a) => [
                'id'          => $a->id,
                'type'        => $a->type,
                'body'        => $a->body,
                'occurred_at' => $a->occurred_at?->toIso8601String(),
                'actor'       => [
                    'id'   => $a->employee?->id,
                    'name' => $a->employee?->full_name ?? $a->author?->name,
                ],
            ]),
        ]);
    }

    public function comments(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $comments = $project->comments()
            ->with(['employee', 'replies.employee'])
            ->get();

        return response()->json([
            'data' => $comments->map(fn (ProjectComment $c) => $this->commentPayload($c)),
        ]);
    }

    // ── Resources ─────────────────────────────────────────────────────────

    public function storeAttachment(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'file'  => ['required', 'file', 'max:20480',
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg,zip'],
            'label' => ['nullable', 'string', 'max:255'],
            'document_type' => ['sometimes', Rule::in(ProjectAttachment::DOCUMENT_TYPES)],
            'project_task_id' => ['nullable', 'integer',
                Rule::exists('project_tasks', 'id')->where('project_id', $project->id)],
        ]);

        $file = $request->file('file');

        $project->attachments()->create([
            'organization_id' => $project->organization_id,
            'project_task_id' => $data['project_task_id'] ?? null,
            'document_type'   => $data['document_type'] ?? 'internal',
            'label'           => ($data['label'] ?? null) ?: $file->getClientOriginalName(),
            'original_name'   => $file->getClientOriginalName(),
            // Encrypted before it reaches storage, like every other class.
            'storage_path'    => DocumentVault::store($file, 'project_documents', "projects/{$project->id}"),
            'mime_type'       => $file->getClientMimeType(),
            'size_bytes'      => $file->getSize(),
            'uploaded_by'     => $request->user()->id,
        ]);

        return response()->json([
            'data' => $project->attachments()->get()->map(fn ($a) => $this->attachmentPayload($a)),
        ]);
    }

    public function downloadAttachment(Project $project, ProjectAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $project);
        abort_unless($attachment->project_id === $project->id, 404);

        return DocumentVault::download(
            'project_documents', $attachment->storage_path,
            $attachment->original_name, $attachment->mime_type,
        );
    }

    // ── Shapes ────────────────────────────────────────────────────────────

    private function rules(Request $request, Project $project, ?ProjectTask $task = null): array
    {
        return $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:50000'],
            'status'      => ['sometimes', Rule::in(ProjectTask::STATUSES)],
            'priority'    => ['sometimes', Rule::in(ProjectTask::PRIORITIES)],
            'deadline'    => ['nullable', 'date'],

            'parent_task_id' => ['nullable', 'integer',
                Rule::exists('project_tasks', 'id')->where('project_id', $project->id)
                    ->when($task !== null, fn ($q) => $q->whereNot('id', $task?->id))],
        ]);
    }

    private function payload(ProjectTask $task): array
    {
        return [
            'id'          => $task->id,
            'reference'   => $task->reference,
            'title'       => $task->title,
            'description' => $task->description,
            'status'      => $task->status,
            'priority'    => $task->priority,
            'deadline'    => $task->deadline?->toDateString(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'position'    => $task->position,
            'parent_task_id' => $task->parent_task_id,

            'is_done'    => $task->is_done,
            'is_overdue' => $task->is_overdue,
            // Surfaced, not enforced: refusing to start a task because a
            // dependency is open gets worked around by deleting the dependency.
            'is_blocked' => $task->is_blocked,

            'assignees' => $this->members($task, 'assignee'),
            'assisting' => $this->members($task, 'assisting'),

            'dependencies' => $task->relationLoaded('dependencies')
                ? $task->dependencies->map(fn ($d) => [
                    'id' => $d->id, 'title' => $d->title, 'status' => $d->status,
                ])->values()
                : [],

            'subtasks' => $task->relationLoaded('subtasks')
                ? $task->subtasks->map(fn ($s) => $this->payload($s))->values()
                : [],

            'attachments' => $task->relationLoaded('attachments')
                ? $task->attachments->map(fn ($a) => $this->attachmentPayload($a))->values()
                : [],

            'comments' => $task->relationLoaded('comments')
                ? $task->comments->map(fn ($c) => $this->commentPayload($c))->values()
                : [],
        ];
    }

    private function members(ProjectTask $task, string $capacity): array
    {
        if (! $task->relationLoaded('members')) {
            return [];
        }

        return $task->members->where('capacity', $capacity)->map(fn ($m) => [
            'id'       => $m->id,
            'employee' => ['id' => $m->employee?->id, 'name' => $m->employee?->full_name],
        ])->values()->all();
    }

    private function commentPayload(ProjectComment $c): array
    {
        return [
            'id'      => $c->id,
            'body'    => $c->body,
            'author'  => ['id' => $c->employee?->id, 'name' => $c->employee?->full_name],
            'created_at' => $c->created_at?->toIso8601String(),
            'replies' => $c->relationLoaded('replies')
                ? $c->replies->map(fn ($r) => $this->commentPayload($r))->values()
                : [],
        ];
    }

    private function attachmentPayload(ProjectAttachment $a): array
    {
        return [
            'id' => $a->id, 'label' => $a->label, 'document_type' => $a->document_type,
            'original_name' => $a->original_name, 'size_bytes' => $a->size_bytes,
            'project_task_id' => $a->project_task_id,
            'uploaded_at' => $a->created_at?->toIso8601String(),
        ];
    }
}
