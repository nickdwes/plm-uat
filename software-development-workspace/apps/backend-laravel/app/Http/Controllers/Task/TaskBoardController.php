<?php

namespace App\Http\Controllers\Task;

use App\Http\Controllers\Controller;
use App\Models\Task\Task;
use App\Models\Task\TaskRequest;
use App\Models\Task\TaskRequiredDocument;
use App\Support\Account\ReferenceSequence;
use App\Support\Authorization\Permission;
use App\Support\Storage\DocumentVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tasks across the whole system, not one project's.
 *
 * A quarter of the legacy's 9,055 tasks carried a project; the rest were
 * support, training, meetings and plain internal work. This is the entry point
 * for all of them — the project-nested endpoints in TaskController remain, as
 * a filtered view of the same table.
 *
 * Gated on `tasks.*`, which has been in the permission catalogue unused since
 * it was written. A task is not a project artefact and should not need project
 * permissions to raise.
 */
class TaskBoardController extends Controller
{
    private const DETAIL = [
        'assignee', 'members.employee', 'subtasks.members.employee', 'dependencies',
        'requests.toEmployee', 'requests.requester', 'requiredDocuments', 'project',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Task::class);

        $q = Task::query()->with(['assignee', 'project', 'members.employee'])->roots();

        /*
         * Someone holding only `view_own` sees what they are doing, helping
         * with, or have been asked about — not the whole company's workload.
         */
        if (! $request->user()->hasPermission(Permission::TASKS_VIEW)) {
            $me = $request->user()->employeeId();
            $q->where(fn ($w) => $w->where('assigned_to', $me)
                ->orWhereHas('members', fn ($m) => $m->where('employee_id', $me))
                ->orWhereHas('requests', fn ($r) => $r->where('to_employee_id', $me)));
        }

        foreach (['category', 'status', 'priority'] as $key) {
            if ($value = $request->string($key)->toString()) {
                $q->where($key, $value);
            }
        }

        foreach (['project_id', 'opportunity_id', 'lead_id', 'research_id', 'account_id', 'assigned_to'] as $key) {
            if ($id = $request->integer($key)) {
                $q->where($key, $id);
            }
        }

        match ($request->string('state')->toString()) {
            // The question people arrive with, and one a status filter cannot
            // answer.
            'overdue' => $q->open()->whereDate('deadline', '<', now()->toDateString()),
            'open'    => $q->open(),
            'mine'    => $q->where('assigned_to', $request->user()->employeeId()),
            default   => null,
        };

        if ($term = trim((string) $request->query('search', ''))) {
            $q->where('title', 'ilike', '%' . $term . '%');
        }

        $tasks = $q->latest('id')->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'data' => collect($tasks->items())->map(fn (Task $t) => $this->row($t)),
            'meta' => ['total' => $tasks->total(), 'per_page' => $tasks->perPage()],
        ]);
    }

    /**
     * What has been asked of me, and what I have asked of others.
     *
     * The legacy had 354 delegations of which 261 were never answered, because
     * nothing ever showed somebody they had been asked. This endpoint is that
     * missing screen.
     */
    public function inbox(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Task::class);

        $me = $request->user()->employeeId();

        $incoming = TaskRequest::with(['task', 'requester'])
            ->where('to_employee_id', $me)->awaiting()->latest('id')->get();

        $outgoing = TaskRequest::with(['task', 'toEmployee'])
            ->where('requested_by', $me)->awaiting()->latest('id')->get();

        return response()->json([
            'incoming' => $incoming->map(fn ($r) => $this->requestPayload($r)),
            // Shown so somebody can chase their own unanswered asks rather
            // than assuming silence meant yes.
            'outgoing' => $outgoing->map(fn ($r) => $this->requestPayload($r)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Task::class);

        $orgId = app('current.organization_id');

        $task = Task::create($this->rules($request, $orgId) + [
            'reference'       => ReferenceSequence::next('TK', 'tasks', $orgId),
            'organization_id' => $orgId,
            'created_by'      => $request->user()->id,
        ]);

        return response()->json(['data' => $this->row($task->load(self::DETAIL))], 201);
    }

    public function show(Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        return response()->json(['data' => $this->row($task->load(self::DETAIL))]);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $data = $this->rules($request, $task->organization_id);

        // Completing stamps the time; reopening clears it — the same rule as
        // everywhere else, so a finished timestamp never survives reopening.
        if (($data['status'] ?? $task->status) === Task::DONE) {
            $data['completed_at'] = $task->completed_at ?? now();
        } elseif (array_key_exists('status', $data)) {
            $data['completed_at'] = null;
        }

        $task->update($data);

        return response()->json(['data' => $this->row($task->fresh(self::DETAIL))]);
    }

    // ── Requests ──────────────────────────────────────────────────────────

    /**
     * Ask somebody to assist, take over, or collaborate.
     *
     * Asking rather than assigning is the whole point. The legacy's "helping"
     * was accepted 98% of the time precisely because it was a request — people
     * agree to what they were asked about.
     */
    public function storeRequest(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $data = $request->validate([
            'kind'            => ['required', Rule::in(TaskRequest::KINDS)],
            'to_employee_id'  => ['required', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $task->organization_id)],
            'request_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        if ((int) $data['to_employee_id'] === (int) $task->assigned_to) {
            throw ValidationException::withMessages([
                'to_employee_id' => 'They already own this task.',
            ]);
        }

        $task->requests()->updateOrCreate(
            ['to_employee_id' => $data['to_employee_id'], 'kind' => $data['kind']],
            [
                'organization_id' => $task->organization_id,
                'request_comment' => $data['request_comment'] ?? null,
                'requested_by'    => $request->user()->employeeId(),
                // Asking again after a decline reopens it rather than leaving
                // the old answer standing.
                'status'          => 'pending',
                'response_comment' => null,
                'responded_at'    => null,
            ],
        );

        return response()->json(['data' => $this->row($task->fresh(self::DETAIL))]);
    }

    /**
     * Answer a request.
     *
     * Only the person asked may answer — that is what makes it a request
     * rather than an assignment with extra steps.
     */
    public function respondToRequest(Request $request, Task $task, TaskRequest $taskRequest): JsonResponse
    {
        abort_unless($taskRequest->task_id === $task->id, 404);
        abort_unless(
            $taskRequest->to_employee_id === $request->user()->employeeId(),
            403,
            'Only the person asked can answer this.',
        );

        $data = $request->validate([
            'status'           => ['required', Rule::in(['accepted', 'declined'])],
            'response_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $taskRequest->update($data + ['responded_at' => now()]);

        /*
         * Accepting a delegation moves ownership. Assist and collaborate do
         * not — they add a helper, and conflating the two is how a task ends
         * up with nobody accountable.
         */
        if ($data['status'] === 'accepted') {
            if ($taskRequest->kind === 'delegate') {
                $task->update(['assigned_to' => $taskRequest->to_employee_id]);
            } else {
                $task->members()->firstOrCreate(
                    ['employee_id' => $taskRequest->to_employee_id, 'capacity' => 'assisting'],
                    ['organization_id' => $task->organization_id],
                );
            }
        }

        return response()->json(['data' => $this->row($task->fresh(self::DETAIL))]);
    }

    // ── Required documents ────────────────────────────────────────────────

    public function storeRequiredDocument(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        // Named empty. The requirement is the checklist item; the file follows
        // whenever it arrives, and until then the gap is visible.
        $task->requiredDocuments()->create($data + [
            'organization_id' => $task->organization_id,
            'created_by'      => $request->user()->id,
        ]);

        return response()->json(['data' => $this->row($task->fresh(self::DETAIL))]);
    }

    /**
     * Satisfy a requirement — with a file, or by pointing at one already on
     * the project.
     *
     * Both routes exist because three quarters of tasks have no project, so a
     * requirement that could only be met by a project attachment could never
     * be ticked on most of them.
     */
    public function fulfilRequiredDocument(Request $request, Task $task, TaskRequiredDocument $document): JsonResponse
    {
        $this->authorize('update', $task);
        abort_unless($document->task_id === $task->id, 404);

        $data = $request->validate([
            'file'  => ['required_without:project_attachment_id', 'file', 'max:20480',
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg,zip'],
            // Scoped to this task's own attachments; a requirement cannot be
            // satisfied by somebody else's file.
            'project_attachment_id' => ['required_without:file', 'nullable', 'integer',
                Rule::exists('project_attachments', 'id')->where('task_id', $task->id)],
        ]);

        if ($file = $request->file('file')) {
            $document->update([
                'original_name' => $file->getClientOriginalName(),
                // Encrypted before it reaches storage, like every other class.
                'storage_path'  => DocumentVault::store($file, 'project_documents', "tasks/{$task->id}"),
                'mime_type'     => $file->getClientMimeType(),
                'size_bytes'    => $file->getSize(),
                'uploaded_by'   => $request->user()->id,
                'fulfilled_at'  => now(),
            ]);
        } else {
            $document->update([
                'project_attachment_id' => $data['project_attachment_id'],
                'fulfilled_at'          => now(),
            ]);
        }

        return response()->json(['data' => $this->row($task->fresh(self::DETAIL))]);
    }

    /** The file that satisfied a requirement, decrypted on the way out. */
    public function downloadRequiredDocument(Task $task, TaskRequiredDocument $document): StreamedResponse
    {
        $this->authorize('view', $task);
        abort_unless($document->task_id === $task->id, 404);
        abort_if($document->storage_path === null, 404);

        return DocumentVault::download(
            'project_documents', $document->storage_path,
            $document->original_name ?? $document->name, $document->mime_type,
        );
    }

    // ── Shapes ────────────────────────────────────────────────────────────

    private function rules(Request $request, int $orgId): array
    {
        $scoped = fn (string $table) => Rule::exists($table, 'id')->where('organization_id', $orgId);

        return $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:50000'],
            'category'    => ['sometimes', Rule::in(Task::CATEGORIES)],
            'status'      => ['sometimes', Rule::in(Task::STATUSES)],
            'priority'    => ['sometimes', Rule::in(Task::PRIORITIES)],
            'deadline'    => ['nullable', 'date'],
            'started_on'  => ['nullable', 'date'],

            // Days, so estimate against actual is answerable. 90% of legacy
            // tasks carried one and nothing ever read it.
            'effort_estimate_days' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'requires_approval'    => ['sometimes', 'boolean'],

            'assigned_to' => ['nullable', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $orgId)],

            // Every link optional — most tasks belong to nothing but the
            // person doing them.
            'project_id'     => ['nullable', 'integer', $scoped('projects')],
            'opportunity_id' => ['nullable', 'integer', $scoped('opportunities')],
            'lead_id'        => ['nullable', 'integer', $scoped('leads')],
            'research_id'    => ['nullable', 'integer', $scoped('researches')],
            'account_id'     => ['nullable', 'integer', $scoped('accounts')],
            'parent_task_id' => ['nullable', 'integer', $scoped('tasks')],
        ]);
    }

    private function row(Task $t): array
    {
        return [
            'id'        => $t->id,
            'reference' => $t->reference,
            'title'     => $t->title,
            'description' => $t->description,
            'category'  => $t->category,
            'status'    => $t->status,
            'priority'  => $t->priority,
            'deadline'  => $t->deadline?->toDateString(),
            'started_on' => $t->started_on?->toDateString(),
            'completed_at' => $t->completed_at?->toIso8601String(),

            'is_done'    => $t->is_done,
            'is_overdue' => $t->is_overdue,
            'is_blocked' => $t->is_blocked,

            /*
             * Estimate against actual — the comparison the legacy had the data
             * for on nine tasks in ten and never made.
             */
            'effort_estimate_days' => $t->effort_estimate_days,
            'elapsed_days'         => $t->elapsed_days,
            'effort_variance'      => $t->effort_variance,

            'requires_approval' => $t->requires_approval,
            'approved_at'       => $t->approved_at?->toIso8601String(),

            'assignee' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->full_name] : null,

            'project' => $t->relationLoaded('project') && $t->project
                ? ['id' => $t->project->id, 'name' => $t->project->name] : null,

            'assisting' => $t->relationLoaded('members')
                ? $t->members->where('capacity', 'assisting')->map(fn ($m) => [
                    'id' => $m->id, 'employee' => ['id' => $m->employee?->id, 'name' => $m->employee?->full_name],
                ])->values()
                : [],

            'requests' => $t->relationLoaded('requests')
                ? $t->requests->map(fn ($r) => $this->requestPayload($r))->values()
                : [],

            'required_documents' => $t->relationLoaded('requiredDocuments')
                ? $t->requiredDocuments->map(fn ($d) => [
                    'id' => $d->id, 'name' => $d->name,
                    'is_fulfilled' => $d->is_fulfilled,
                    'fulfilled_at' => $d->fulfilled_at?->toIso8601String(),
                    'original_name' => $d->original_name,
                    'has_file'      => $d->storage_path !== null,
                ])->values()
                : [],

            'subtasks' => $t->relationLoaded('subtasks')
                ? $t->subtasks->map(fn ($s) => $this->row($s))->values()
                : [],
        ];
    }

    private function requestPayload(TaskRequest $r): array
    {
        return [
            'id'     => $r->id,
            'kind'   => $r->kind,
            'status' => $r->status,
            'request_comment'  => $r->request_comment,
            'response_comment' => $r->response_comment,
            'responded_at'     => $r->responded_at?->toIso8601String(),
            // Asked and never answered for over a week. The legacy left 74% of
            // delegations like this with nothing surfacing them.
            'is_stale' => $r->is_stale,
            'to'        => $r->relationLoaded('toEmployee') && $r->toEmployee
                ? ['id' => $r->toEmployee->id, 'name' => $r->toEmployee->full_name] : null,
            'requester' => $r->relationLoaded('requester') && $r->requester
                ? ['id' => $r->requester->id, 'name' => $r->requester->full_name] : null,
            'task' => $r->relationLoaded('task') && $r->task
                ? ['id' => $r->task->id, 'reference' => $r->task->reference, 'title' => $r->task->title] : null,
        ];
    }
}
