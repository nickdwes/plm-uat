<?php

namespace App\Http\Controllers\Task;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectAttachment;
use App\Models\Project\ProjectComment;
use App\Models\Task\Task;
use App\Models\Task\TaskMember;
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
class TaskController extends Controller
{
    public function __construct(private readonly ProjectActivityService $activities) {}

    private const DETAIL = ['members.employee', 'subtasks.members.employee', 'dependencies', 'attachments', 'logistics', 'prerequisites'];

    // ── Tasks ─────────────────────────────────────────────────────────────

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $tasks = $project->rootTasks()
            ->with(['members.employee', 'subtasks.members.employee', 'dependencies', 'logistics', 'prerequisites'])
            // Filterable by requirement, for planning and procurement tracking:
            // ?requires=needs_travel, or ?requires=approval for sign-off pending.
            ->when($request->string('requires')->toString(), function ($q, $requires) {
                $requires === 'approval'
                    ? $q->whereHas('logistics', fn ($l) => $l->where('requires_approval', true))
                    : (in_array($requires, \App\Models\Task\TaskLogistics::NEEDS, true)
                        ? $q->whereHas('logistics', fn ($l) => $l->where($requires, true))
                        : null);
            })
            ->get();

        return response()->json(['data' => $tasks->map(fn ($t) => $this->payload($t))]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $this->rules($request, $project);

        $task = $project->tasks()->create($data + [
            'reference'       => ReferenceSequence::next('TK', 'tasks', $project->organization_id),
            'organization_id' => $project->organization_id,
            // Appended rather than inserted: a new task belongs at the end
            // until somebody moves it.
            'position'        => (int) $project->tasks()->max('position') + 1,
            'created_by'      => $request->user()->id,
        ]);

        $this->applyAssignmentAndDependencies($request, $project, $task);
        // The form always sends this section (it is mandatory there); the API
        // stays lenient so other callers and generated tasks aren't forced to.
        // Whenever it IS sent, a flagged requirement still needs its cost,
        // lead time and — if it needs approval — a note.
        $this->applyLogistics($request, $project, $task, required: false);

        $this->activities->record(
            $project, 'task', "Added “{$task->title}”", $task,
            $request->user()->id, $request->user()->employeeId(),
        );

        return response()->json(['data' => $this->payload($task->fresh(self::DETAIL))], 201);
    }

    /**
     * Seed the standard PM activity checklist. Idempotent — skips any activity
     * whose title is already on the project — and attaches each to its default
     * phase where that phase exists.
     */
    public function generatePmActivities(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        // Default phase name (lowercased) → id, for attaching activities.
        $phaseByName = $project->allPhases()->get(['id', 'name'])
            ->keyBy(fn ($p) => strtolower($p->name));

        $existing = $project->tasks()->pluck('title')->map(fn ($t) => strtolower($t))->all();
        $position = (int) $project->tasks()->max('position');
        $created  = 0;

        // The checklist is the organisation's own, defined on the PM task
        // definitions page (seeded from the house defaults). Only active
        // definitions are generated, in their defined order.
        \App\Models\Project\PmTaskDefinition::ensureSeeded($project->organization_id);
        $definitions = \App\Models\Project\PmTaskDefinition::active()
            ->orderBy('position')->orderBy('id')->get();

        foreach ($definitions as $definition) {
            $title = $definition->title;
            if (in_array(strtolower($title), $existing, true)) {
                continue;
            }

            $phaseId = $definition->phase
                ? $phaseByName->get(strtolower($definition->phase))?->id
                : null;

            $project->tasks()->create([
                'organization_id' => $project->organization_id,
                'category'   => 'project',
                'title'      => $title,
                'status'     => 'todo',
                'priority'   => $definition->priority,
                'weekly_okr' => $definition->weekly_okr,
                'phase_id'   => $phaseId,
                'reference'  => ReferenceSequence::next('TK', 'tasks', $project->organization_id),
                'position'   => ++$position,
                'created_by' => $request->user()->id,
            ]);
            $existing[] = strtolower($title);
            $created++;
        }

        if ($created > 0) {
            $this->activities->record(
                $project, 'task', "Generated {$created} standard PM activities", null,
                $request->user()->id, $request->user()->employeeId(),
            );
        }

        return response()->json([
            'data'    => $project->rootTasks()->with(self::DETAIL)->get()->map(fn ($t) => $this->payload($t)),
            'created' => $created,
        ]);
    }

    /**
     * Assignment and dependencies set at creation time — the enriched task
     * creator lets a PM pick engineers off a role (primary vs secondary
     * responsibility) and the prerequisite tasks in one go, rather than
     * re-opening the task to add them.
     */
    private function applyAssignmentAndDependencies(Request $request, Project $project, Task $task): void
    {
        $data = $request->validate([
            'assignees'               => ['sometimes', 'array'],
            'assignees.*.employee_id' => ['required', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $project->organization_id)],
            'assignees.*.capacity'    => ['required', Rule::in(TaskMember::CAPACITIES)],

            'depends_on_task_ids'   => ['sometimes', 'array'],
            'depends_on_task_ids.*' => ['integer',
                Rule::exists('tasks', 'id')->where('project_id', $project->id)],
        ]);

        foreach ($data['assignees'] ?? [] as $assignee) {
            $task->members()->firstOrCreate(
                ['employee_id' => $assignee['employee_id'], 'capacity' => $assignee['capacity']],
                ['organization_id' => $project->organization_id],
            );
        }

        foreach (array_unique($data['depends_on_task_ids'] ?? []) as $depId) {
            if ((int) $depId === $task->id) {
                continue; // a fresh task can't sensibly wait on itself.
            }
            $task->dependencies()->syncWithoutDetaching([
                $depId => ['organization_id' => $project->organization_id],
            ]);
        }
    }

    /**
     * Requirements & logistics — mandatory on create so no task is planned
     * without the question having been answered, and so what a delivery needs
     * arranged (and what it will cost) is captured while it is being scoped.
     *
     * When any requirement is flagged, a cost estimate and lead time are
     * required with it — a "needs procurement" with no figure is exactly the
     * gap that breaks a budget — and an approval flagged needs its note.
     */
    private function applyLogistics(Request $request, Project $project, Task $task, bool $required): void
    {
        $needsRules = [];
        foreach (\App\Models\Task\TaskLogistics::NEEDS as $flag) {
            $needsRules["logistics.$flag"] = ['sometimes', 'boolean'];
        }

        $data = $request->validate([
            'logistics'                    => [$required ? 'required' : 'sometimes', 'array'],
            'logistics.details'            => ['nullable', 'string', 'max:5000'],
            'logistics.requires_approval'  => ['sometimes', 'boolean'],
            'logistics.travel_location'    => ['nullable', 'string', 'max:160'],
            'logistics.travel_mode'        => ['nullable', 'string', 'max:20'],
        ] + $needsRules);

        // Update-with-no-logistics-key leaves what is there untouched.
        if (! array_key_exists('logistics', $data)) {
            return;
        }

        $log = $data['logistics'] ?? [];

        $flags = [];
        foreach (\App\Models\Task\TaskLogistics::NEEDS as $flag) {
            $flags[$flag] = (bool) ($log[$flag] ?? false);
        }

        $logistics = $task->logistics()->updateOrCreate(
            ['task_id' => $task->id],
            $flags + [
                'organization_id'   => $project->organization_id,
                'project_id'        => $project->id,
                'details'           => $log['details'] ?? null,
                'travel_location'   => $log['travel_location'] ?? null,
                'travel_mode'       => $log['travel_mode'] ?? null,
                'requires_approval' => (bool) ($log['requires_approval'] ?? false),
                'created_by'        => $request->user()->id,
            ],
        );

        // Each flag becomes a tracked prerequisite action on the task.
        app(\App\Services\Task\TaskPrerequisiteSync::class)->sync($task->fresh(), $logistics, $request->user()->id);
    }

    public function show(Project $project, Task $task): JsonResponse
    {
        $this->authorize('view', $project);
        abort_unless($task->project_id === $project->id, 404);

        return response()->json([
            'data' => $this->payload($task->load(array_merge(self::DETAIL, ['comments.replies.employee', 'comments.employee']))),
        ]);
    }

    public function update(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($task->project_id === $project->id, 404);

        $data = $this->rules($request, $project, $task);

        /*
         * Completing stamps the time; reopening clears it. A completed_at left
         * on a reopened task is the same lie as a delivery date on a reopened
         * project — it would report finished work that is back in progress.
         */
        $next = $data['status'] ?? $task->status;

        /*
         * The closure summary is enforced here too, not only on the task board.
         * This route can finish the same task, and a rule one of the two doors
         * lets through is not a rule.
         */
        if ($next === Task::DONE && $next !== $task->status && $task->needsClosureSummary()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'closure' => ['Say what came of this task before finishing it — open it from Tasks to close it off.'],
            ]);
        }

        if ($next === Task::DONE) {
            $data['completed_at'] = $task->completed_at ?? now();
        } elseif (array_key_exists('status', $data)) {
            $data['completed_at'] = null;
        }

        $was = $task->status;
        $task->update($data);

        $this->applyLogistics($request, $project, $task, required: false);

        $this->activities->taskChanged(
            $project, $task->fresh(), $was,
            $request->user()->id, $request->user()->employeeId(),
        );

        return response()->json(['data' => $this->payload($task->fresh(self::DETAIL))]);
    }

    /**
     * A PM works a prerequisite down its status — open → in progress → done, or
     * blocked. Advisory: it records where the arrangement stands, it does not
     * refuse to start the task.
     */
    public function updatePrerequisite(Request $request, Project $project, Task $task, \App\Models\Task\TaskPrerequisite $prerequisite): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($task->project_id === $project->id && $prerequisite->task_id === $task->id, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(\App\Models\Task\TaskPrerequisite::STATUSES)],
            'detail' => ['nullable', 'string', 'max:2000'],
        ]);

        $done = $data['status'] === \App\Models\Task\TaskPrerequisite::STATUS_DONE;
        $prerequisite->update([
            'status'      => $data['status'],
            'detail'      => array_key_exists('detail', $data) ? $data['detail'] : $prerequisite->detail,
            'resolved_at' => $done ? now() : null,
            'resolved_by' => $done ? $request->user()->id : null,
        ]);

        return response()->json(['data' => $this->prerequisitePayload($prerequisite->fresh())]);
    }

    /**
     * Raise the real cross-module record a prerequisite stands for — today, a
     * project procurement request for an asset / software / procurement item.
     * The prerequisite keeps a live link to what it raised.
     */
    public function raisePrerequisite(Request $request, Project $project, Task $task, \App\Models\Task\TaskPrerequisite $prerequisite): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($task->project_id === $project->id && $prerequisite->task_id === $task->id, 404);

        app(\App\Services\Task\PrerequisiteFulfiller::class)
            ->raise($prerequisite, $request->user()->id);

        return response()->json(['data' => $this->prerequisitePayload($prerequisite->fresh())]);
    }

    public function destroy(Project $project, Task $task): JsonResponse
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
            'order.*' => ['integer', Rule::exists('tasks', 'id')->where('project_id', $project->id)],
        ]);

        foreach ($data['order'] as $position => $id) {
            Task::where('id', $id)->update(['position' => $position]);
        }

        return $this->index($request, $project);
    }

    // ── Members ───────────────────────────────────────────────────────────

    public function storeMember(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('assign', $project);
        abort_unless($task->project_id === $project->id, 404);

        $data = $request->validate([
            'employee_id' => ['required', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $project->organization_id)],
            'capacity' => ['sometimes', Rule::in(TaskMember::CAPACITIES)],
        ]);

        $task->members()->firstOrCreate([
            'employee_id' => $data['employee_id'],
            'capacity'    => $data['capacity'] ?? 'assignee',
        ], ['organization_id' => $project->organization_id]);

        return response()->json(['data' => $this->payload($task->fresh(self::DETAIL))]);
    }

    public function destroyMember(Project $project, Task $task, TaskMember $member): JsonResponse
    {
        $this->authorize('assign', $project);
        abort_unless($task->project_id === $project->id && $member->task_id === $task->id, 404);

        $member->delete();

        return response()->json(['data' => $this->payload($task->fresh(self::DETAIL))]);
    }

    // ── Dependencies ──────────────────────────────────────────────────────

    public function storeDependency(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($task->project_id === $project->id, 404);

        $data = $request->validate([
            // Scoped to this project: a task cannot wait on somebody else's.
            'depends_on_task_id' => ['required', 'integer',
                Rule::exists('tasks', 'id')->where('project_id', $project->id)],
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

    public function destroyDependency(Project $project, Task $task, Task $dependency): JsonResponse
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

        foreach (Task::find($prerequisiteId)?->dependencies()->pluck('depends_on_task_id') ?? [] as $next) {
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
            'task_id' => ['nullable', 'integer',
                Rule::exists('tasks', 'id')->where('project_id', $project->id)],
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
            'task_id' => ['nullable', 'integer',
                Rule::exists('tasks', 'id')->where('project_id', $project->id)],
        ]);

        $file = $request->file('file');

        $project->attachments()->create([
            'organization_id' => $project->organization_id,
            'task_id' => $data['task_id'] ?? null,
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

    /** Project-level documents — the charter set, not task or milestone files. */
    public function documents(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'data' => $project->attachments()
                ->whereNull('task_id')->whereNull('milestone_id')
                ->latest('id')->get()->map(fn ($a) => $this->attachmentPayload($a)),
        ]);
    }

    public function destroyAttachment(Project $project, ProjectAttachment $attachment): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($attachment->project_id === $project->id, 404);

        $attachment->delete();

        return response()->json(['message' => 'Document removed.']);
    }

    // ── Shapes ────────────────────────────────────────────────────────────

    private function rules(Request $request, Project $project, ?Task $task = null): array
    {
        return $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:50000'],
            'status'      => ['sometimes', Rule::in(Task::STATUSES)],
            'priority'    => ['sometimes', Rule::in(Task::PRIORITIES)],
            // Start and expected closure. Closure cannot precede the start.
            'started_on'  => ['nullable', 'date'],
            'deadline'    => ['nullable', 'date', 'after_or_equal:started_on'],

            'parent_task_id' => ['nullable', 'integer',
                Rule::exists('tasks', 'id')->where('project_id', $project->id)
                    ->when($task !== null, fn ($q) => $q->whereNot('id', $task?->id))],

            // Which lifecycle phase this work sits under (project tasks only).
            'phase_id' => ['nullable', 'integer',
                Rule::exists('project_phases', 'id')->where('project_id', $project->id)],

            // Raised to a weekly objective (OKR) on the weekly report.
            'weekly_okr' => ['sometimes', 'boolean'],

            // Estimated effort in man-days — feeds the resource plan.
            'effort_estimate_days' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
        ]);
    }

    private function prerequisitePayload(\App\Models\Task\TaskPrerequisite $p): array
    {
        [$linkedKind, $linkedRef] = $this->linkedInfo($p);
        $fulfiller = app(\App\Services\Task\PrerequisiteFulfiller::class);

        return [
            'id'                 => $p->id,
            'type'               => $p->type,
            'label'              => $p->label,
            'status'             => $p->status,
            'detail'             => $p->detail,
            'procurement_needed' => $p->procurement_needed,
            'due_on'             => $p->due_on?->toDateString(),
            'linked_kind'        => $linkedKind,
            'linked_reference'   => $linkedRef,
            'can_raise'          => $linkedKind === null && $fulfiller->canRaise($p),
            'raise_label'        => $fulfiller->canRaiseProcurement($p) ? 'Raise procurement'
                : ($fulfiller->canRaiseEngagement($p) ? 'Raise engagement' : null),
        ];
    }

    /**
     * What a prerequisite has raised, resolved to a kind + reference the UI can
     * show — a procurement request, or the travel request its cycle generated.
     *
     * @return array{0:?string,1:?string} [kind, reference]
     */
    private function linkedInfo(\App\Models\Task\TaskPrerequisite $p): array
    {
        return match ($p->linked_type) {
            \App\Models\Procurement\ProcurementRequest::class
                => ['procurement', \App\Models\Procurement\ProcurementRequest::find($p->linked_id)?->reference],
            \App\Models\Project\ProjectTravelRequest::class
                => ['travel', \App\Models\Project\ProjectTravelRequest::find($p->linked_id)?->reference],
            \App\Models\Project\ProjectVendorEngagement::class
                => ['vendor', \App\Models\Project\ProjectVendorEngagement::find($p->linked_id)?->reference],
            default => [null, null],
        };
    }

    private function logisticsPayload(\App\Models\Task\TaskLogistics $l): array
    {
        $out = [
            'details'           => $l->details,
            'travel_location'   => $l->travel_location,
            'travel_mode'       => $l->travel_mode,
            'requires_approval' => $l->requires_approval,
            'needs_any'         => $l->hasAnyRequirement(),
        ];
        foreach (\App\Models\Task\TaskLogistics::NEEDS as $flag) {
            $out[$flag] = (bool) $l->{$flag};
        }

        return $out;
    }

    private function payload(Task $task): array
    {
        return [
            'id'          => $task->id,
            'reference'   => $task->reference,
            'title'       => $task->title,
            'description' => $task->description,
            'status'      => $task->status,
            'priority'    => $task->priority,
            'started_on'  => $task->started_on?->toDateString(),
            'deadline'    => $task->deadline?->toDateString(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'position'    => $task->position,
            'parent_task_id' => $task->parent_task_id,
            'phase_id'    => $task->phase_id,
            'weekly_okr'  => (bool) $task->weekly_okr,
        'effort_estimate_days' => $task->effort_estimate_days,

            'is_done'    => $task->is_done,
            'is_overdue' => $task->is_overdue,
            // Surfaced, not enforced: refusing to start a task because a
            // dependency is open gets worked around by deleting the dependency.
            'is_blocked' => $task->is_blocked,

            'logistics' => $task->relationLoaded('logistics') && $task->logistics
                ? $this->logisticsPayload($task->logistics) : null,
            'prerequisites' => $task->relationLoaded('prerequisites')
                ? $task->prerequisites->map(fn ($p) => $this->prerequisitePayload($p))->values() : [],

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

    private function members(Task $task, string $capacity): array
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
            'task_id' => $a->task_id,
            'uploaded_at' => $a->created_at?->toIso8601String(),
        ];
    }
}
