<?php

namespace App\Http\Controllers\Task;

use App\Http\Controllers\Controller;
use App\Models\Project\ProjectAttachment;
use App\Models\Project\ProjectComment;
use App\Models\Task\CommentReaction;
use App\Models\Task\Task;
use App\Models\Task\TaskIssue;
use App\Models\Task\TaskRequest;
use App\Models\Task\TaskRequiredDocument;
use App\Support\Account\ReferenceSequence;
use App\Support\Authorization\Permission;
use App\Services\Task\TaskNotifier;
use App\Support\Storage\DocumentVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    public function __construct(private TaskNotifier $notifier)
    {
    }

    private const DETAIL = [
        'assignee', 'approver', 'starter', 'completer',
        'ownerAccount', 'ownerVendorPartner', 'ownerContact', 'activeHandover.coverer',
        'members.employee', 'subtasks.members.employee', 'subtasks.assignee',
        'dependencies', 'externalDependencies', 'requests.toEmployee', 'requests.requester',
        'requiredDocuments', 'project', 'attachments',
        'comments.employee', 'comments.replies.employee', 'comments.reactions',
        'comments.replies.reactions', 'issues.escalations.employee', 'issues.raiser',
    ];

    /**
     * The counts above the board.
     *
     * Derived every time rather than kept on a row. A stored count is a count
     * that drifts the first time somebody changes a status by any route that
     * forgot to decrement it, and the numbers here are small enough that the
     * question does not arise.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Task::class);

        $scope = fn () => $this->visibleTo($request, Task::query()->roots());

        $today = now()->toDateString();

        return response()->json([
            'total'       => $scope()->count(),
            'todo'        => $scope()->where('status', 'todo')->count(),
            'in_progress' => $scope()->where('status', 'in_progress')->count(),
            'awaiting'    => $scope()->where('status', 'awaiting_approval')->count(),
            'completed'   => $scope()->where('status', Task::DONE)->count(),
            // Past its deadline and still open — which is not any one status,
            // and is the number people actually come to the page for.
            'overdue'     => $scope()->open()->whereDate('deadline', '<', $today)->count(),

            /*
             * Owed by somebody outside the company and still open.
             *
             * Counted apart from the rest because it is not our workload. A
             * person chasing eleven things a client owes is not overloaded,
             * and a total that mixes the two says they are.
             */
            'awaiting_others' => $scope()->awaitingOthers()->count(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Task::class);

        // The owner links come with the list because the board badges them —
        // without eager loading that is a query per card.
        $q = Task::query()->with([
            'assignee', 'project', 'members.employee',
            'ownerAccount', 'ownerVendorPartner', 'ownerContact', 'activeHandover.coverer',
        ])->roots();

        $this->visibleTo($request, $q);

        foreach (['category', 'status', 'priority', 'owner_party', 'source'] as $key) {
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
            // Owed by a client or a supplier and not yet done — the list
            // somebody has to work through before chasing anyone.
            'awaiting_others' => $q->awaitingOthers(),
            default   => null,
        };

        if ($term = trim((string) $request->query('search', ''))) {
            $q->where('title', 'ilike', '%' . $term . '%');
        }

        $tasks = $q->latest('id')->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'data' => collect($tasks->items())->map(fn (Task $t) => $this->row($t)),
            'meta' => [
                'total'        => $tasks->total(),
                'per_page'     => $tasks->perPage(),
                'current_page' => $tasks->currentPage(),
                'last_page'    => $tasks->lastPage(),
            ],
        ]);
    }

    /**
     * Someone holding only `view_own` sees what they are doing, helping with,
     * or have been asked about — not the whole company's workload.
     *
     * Shared by the list and the counts above it, so the two can never disagree
     * about what somebody is allowed to see.
     */
    private function visibleTo(Request $request, $q)
    {
        if ($request->user()->hasPermission(Permission::TASKS_VIEW)) {
            return $q;
        }

        $me = $request->user()->employeeId();

        return $q->where(fn ($w) => $w->where('assigned_to', $me)
            // A task waiting on my sign-off is my work, and it has to appear
            // somewhere I look or it waits forever.
            ->orWhere('approver_id', $me)
            // What they are covering while somebody is away. It has to appear
            // in their list or the cover is only a record of good intentions.
            ->orWhereHas('handovers', fn ($h) => $h->standing()->where('to_employee_id', $me))
            ->orWhereHas('members', fn ($m) => $m->where('employee_id', $me))
            ->orWhereHas('requests', fn ($r) => $r->where('to_employee_id', $me)));
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

        $task = Task::create($this->reconcileOwnership($this->rules($request, $orgId), new Task) + [
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

        $data = $this->reconcileOwnership($this->rules($request, $task->organization_id), $task);
        $me   = $request->user()->employeeId();

        $next = $data['status'] ?? $task->status;

        /*
         * A task that needs signing off cannot be finished by finishing it.
         *
         * Marking it done sends it for approval instead. Otherwise
         * `requires_approval` would be a note rather than a rule, and the
         * Awaiting Approval column would only ever hold what somebody
         * remembered to put there.
         */
        if ($next === Task::DONE && $task->requires_approval && $task->approved_at === null) {
            $next = 'awaiting_approval';
            $data['status'] = $next;
        }

        // Completing stamps the time and the person; reopening clears both, so
        // a finished record never survives the state it described.
        if ($next === Task::DONE) {
            $data['completed_at'] = $task->completed_at ?? now();
            $data['completed_by'] = $task->completed_by ?? $me;
        } elseif (array_key_exists('status', $data)) {
            $data['completed_at'] = null;
            $data['completed_by'] = null;
        }

        // Who actually picked it up. Stamped the first time it leaves todo,
        // because that is the moment it happened — not whenever somebody
        // later types a start date.
        if ($next !== 'todo' && $task->started_by === null) {
            $data['started_by'] = $me;
            $data['started_on'] = $data['started_on'] ?? $task->started_on ?? now()->toDateString();
        }

        $task->update($data);

        if ($next === 'awaiting_approval' && $task->approver_id) {
            $this->notifier->awaitingApproval($task->fresh());
        }

        return response()->json(['data' => $this->row($task->fresh(self::DETAIL))]);
    }

    /**
     * Soft-deleted, so a task removed by mistake is recoverable.
     *
     * Its sub-tasks go with it — a sub-task without its parent is an orphan
     * nothing can navigate to.
     */
    public function destroy(Task $task): JsonResponse
    {
        $this->authorize('delete', $task);

        $task->subtasks()->each(fn (Task $child) => $child->delete());
        $task->delete();

        return response()->json(['deleted' => true]);
    }

    // ── Approval ──────────────────────────────────────────────────────────

    /**
     * Sign a task off, or send it back.
     *
     * This existed as three columns, a status and a column on the board, and
     * nothing that could act on any of it — a task could reach Awaiting
     * Approval and stay there forever. 24% of the legacy's tasks were
     * approved, so it was a live path left half-built, which is worse than
     * not building it: the column implies the button.
     *
     * Only the named approver may decide. `requires_approval` on its own said
     * a signature was needed without saying whose, which leaves the task
     * waiting on nobody in particular.
     */
    public function decideApproval(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $me = $request->user()->employeeId();

        abort_unless(
            $task->approver_id !== null && $task->approver_id === $me,
            403,
            'Only the named approver can decide this.',
        );

        $data = $request->validate([
            'approved' => ['required', 'boolean'],
            'note'     => ['nullable', 'string', 'max:2000'],
        ]);

        if ($data['approved']) {
            $task->update([
                'status'       => Task::DONE,
                'approved_at'  => now(),
                'approved_by'  => $request->user()->id,
                'approval_note' => $data['note'] ?? null,
                'completed_at' => $task->completed_at ?? now(),
                'completed_by' => $task->completed_by ?? $task->assigned_to,
            ]);
        } else {
            /*
             * Sent back to in progress, not to todo. The work was done and
             * found wanting; putting it back at the start would say nobody had
             * touched it, and the approval stamps are cleared so a later
             * approval is a fresh decision.
             */
            $task->update([
                'status'        => 'in_progress',
                'approved_at'   => null,
                'approved_by'   => null,
                'approval_note' => $data['note'] ?? null,
                'completed_at'  => null,
                'completed_by'  => null,
            ]);
        }

        $this->notifier->approvalDecided($task->fresh(), $data['approved'], $data['note'] ?? null);

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

        $taskRequest = $task->requests()->updateOrCreate(
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

        // Being asked is the whole mechanism, so it is worth interrupting for.
        $this->notifier->requested($task, $taskRequest);

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

        // Told either way: from the asker's side, silence and a decline look
        // identical until somebody says so.
        $this->notifier->answered($task, $taskRequest->fresh());

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


    // ── Sub-tasks and dependencies ────────────────────────────────────────

    /**
     * A sub-task is a task with a parent.
     *
     * The legacy kept a separate sub-task table where a sub-phase carried a
     * name and little else. One table with a parent gives a sub-task an owner,
     * a deadline, a status and its own sub-tasks.
     */
    public function storeSubtask(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $data = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'deadline'    => ['nullable', 'date'],
            'priority'    => ['sometimes', Rule::in(Task::PRIORITIES)],
            'assigned_to' => ['nullable', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $task->organization_id)],
        ]);

        Task::create($data + [
            'organization_id' => $task->organization_id,
            'parent_task_id'  => $task->id,
            'reference'       => ReferenceSequence::next('TK', 'tasks', $task->organization_id),
            // Inherited, not asked for again: a sub-task of a support task is
            // support work, and of the same project.
            'category'   => $task->category,
            'project_id' => $task->project_id,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->row($task->fresh(self::DETAIL))]);
    }

    /**
     * Something this task is waiting on.
     *
     * Either another task or a plain note — 172 of the legacy's 203 were the
     * latter, things like "awaiting the client's firewall change" that are not
     * anybody's task here. Refusing to record those would just mean the
     * blocker goes unwritten.
     */
    public function storeDependencyLink(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $data = $request->validate([
            'depends_on_task_id' => ['nullable', 'integer', 'different:task',
                Rule::exists('tasks', 'id')->where('organization_id', $task->organization_id)],
            'note' => ['required_without:depends_on_task_id', 'nullable', 'string', 'max:255'],
        ]);

        if (($data['depends_on_task_id'] ?? null) === $task->id) {
            throw ValidationException::withMessages([
                'depends_on_task_id' => 'A task cannot wait on itself.',
            ]);
        }

        if ($id = $data['depends_on_task_id'] ?? null) {
            $task->dependencies()->syncWithoutDetaching([
                $id => [
                    'organization_id' => $task->organization_id,
                    'kind' => 'task', 'note' => $data['note'] ?? null,
                ],
            ]);
        } else {
            // A note-only blocker has no second task to point at. Left null
            // rather than pointed back at this task, which would have left it
            // permanently blocked on itself.
            DB::table('task_dependencies')->insert([
                'organization_id' => $task->organization_id,
                'task_id' => $task->id, 'depends_on_task_id' => null,
                'kind' => 'external', 'note' => $data['note'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return response()->json(['data' => $this->row($task->fresh(self::DETAIL))]);
    }

    // ── Comments ──────────────────────────────────────────────────────────

    /**
     * A comment, or a reply to one.
     *
     * The legacy took 64,578 of these against 9,055 tasks — seven per task.
     * Whatever else the module was, it was where people talked, so this is not
     * a nice-to-have tab.
     */
    public function storeComment(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $data = $request->validate([
            'body'      => ['required', 'string', 'max:20000'],
            'parent_id' => ['nullable', 'integer',
                Rule::exists('project_comments', 'id')->where('task_id', $task->id)],
        ]);

        ProjectComment::create($data + [
            'organization_id' => $task->organization_id,
            'project_id'      => $task->project_id,
            'task_id'         => $task->id,
            'employee_id'     => $request->user()->employeeId(),
            'created_by'      => $request->user()->id,
        ]);

        return response()->json(['data' => $this->row($task->fresh(self::DETAIL))]);
    }

    /** Pin a comment to the top, or unpin it. */
    public function pinComment(Request $request, Task $task, ProjectComment $comment): JsonResponse
    {
        $this->authorize('update', $task);
        abort_unless($comment->task_id === $task->id, 404);

        $comment->update(['is_pinned' => $request->boolean('is_pinned', ! $comment->is_pinned)]);

        return response()->json(['data' => $this->row($task->fresh(self::DETAIL))]);
    }

    /**
     * React, or take the reaction back.
     *
     * The same call both ways, because a reaction is a toggle to the person
     * clicking it and a second endpoint to remove one would only ever be
     * called by the same button.
     */
    public function reactToComment(Request $request, Task $task, ProjectComment $comment): JsonResponse
    {
        $this->authorize('view', $task);
        abort_unless($comment->task_id === $task->id, 404);

        $data = $request->validate(['emoji' => ['required', 'string', 'max:16']]);

        $existing = CommentReaction::where('project_comment_id', $comment->id)
            ->where('employee_id', $request->user()->employeeId())
            ->where('emoji', $data['emoji'])->first();

        if ($existing) {
            $existing->delete();
        } else {
            CommentReaction::create([
                'organization_id'    => $task->organization_id,
                'project_comment_id' => $comment->id,
                'employee_id'        => $request->user()->employeeId(),
                'emoji'              => $data['emoji'],
            ]);
        }

        return response()->json(['data' => $this->row($task->fresh(self::DETAIL))]);
    }

    // ── Attachments ───────────────────────────────────────────────────────

    /** A file on the task. Encrypted before it reaches storage, as always. */
    public function storeAttachment(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $data = $request->validate([
            'file'  => ['required', 'file', 'max:20480',
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg,zip'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');

        $task->attachments()->create([
            'organization_id' => $task->organization_id,
            'project_id'      => $task->project_id,
            'document_type'   => 'internal',
            'label'           => ($data['label'] ?? null) ?: $file->getClientOriginalName(),
            'original_name'   => $file->getClientOriginalName(),
            'storage_path'    => DocumentVault::store($file, 'project_documents', "tasks/{$task->id}"),
            'mime_type'       => $file->getClientMimeType(),
            'size_bytes'      => $file->getSize(),
            'uploaded_by'     => $request->user()->id,
        ]);

        return response()->json(['data' => $this->row($task->fresh(self::DETAIL))]);
    }

    public function downloadAttachment(Task $task, ProjectAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $task);
        abort_unless($attachment->task_id === $task->id, 404);

        return DocumentVault::download(
            'project_documents', $attachment->storage_path,
            $attachment->original_name, $attachment->mime_type,
        );
    }

    // ── Issues ────────────────────────────────────────────────────────────

    /**
     * Raise an issue, and put it in front of somebody in the same breath.
     *
     * The escalation is part of the form rather than a second action because
     * the legacy escalated 212 of its 242 issues — 88%. Making it a separate
     * step would add a click to the common case and leave the other 12%
     * looking like the norm.
     */
    public function storeIssue(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'details'  => ['nullable', 'string', 'max:20000'],
            'priority' => ['sometimes', Rule::in(TaskIssue::PRIORITIES)],
            /*
             * Something that has happened, or something that might.
             *
             * Defaults to issue, which is what this endpoint has always
             * recorded. The day-close screen sends `risk` when an overdue task
             * prompts for one — the deadline that has already gone is not the
             * problem, the one after it is.
             */
            'kind'     => ['sometimes', Rule::in(TaskIssue::KINDS)],
            'escalate_to' => ['nullable', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $task->organization_id)],
        ]);

        $issue = $task->issues()->create([
            'organization_id' => $task->organization_id,
            'name'      => $data['name'],
            'details'   => $data['details'] ?? null,
            'priority'  => $data['priority'] ?? 'high',
            'kind'      => $data['kind'] ?? 'issue',
            'raised_by' => $request->user()->employeeId(),
        ]);

        if ($to = $data['escalate_to'] ?? null) {
            $issue->escalations()->create([
                'organization_id' => $task->organization_id,
                'employee_id'     => $to,
                'raised_by'       => $request->user()->employeeId(),
            ]);

            $this->notifier->escalated($task, $issue, $to, $request->user()->employeeId());
        }

        return response()->json(['data' => $this->row($task->fresh(self::DETAIL))]);
    }

    /**
     * Resolve an issue, or reopen it.
     *
     * Reopening clears who resolved it and when, so a resolution never outlives
     * the state it described — the same rule completion follows on the task.
     */
    public function updateIssue(Request $request, Task $task, TaskIssue $issue): JsonResponse
    {
        $this->authorize('update', $task);
        abort_unless($issue->task_id === $task->id, 404);

        $data = $request->validate([
            'status'   => ['sometimes', Rule::in(TaskIssue::STATUSES)],
            'name'     => ['sometimes', 'string', 'max:255'],
            'details'  => ['nullable', 'string', 'max:20000'],
            'priority' => ['sometimes', Rule::in(TaskIssue::PRIORITIES)],
        ]);

        if (($data['status'] ?? null) === 'resolved') {
            $data['resolved_at'] = $issue->resolved_at ?? now();
            $data['resolved_by'] = $issue->resolved_by ?? $request->user()->employeeId();
        } elseif (($data['status'] ?? null) === 'open') {
            $data['resolved_at'] = null;
            $data['resolved_by'] = null;
        }

        $issue->update($data);

        return response()->json(['data' => $this->row($task->fresh(self::DETAIL))]);
    }

    /** Escalate an issue further, to somebody else. */
    public function escalateIssue(Request $request, Task $task, TaskIssue $issue): JsonResponse
    {
        $this->authorize('update', $task);
        abort_unless($issue->task_id === $task->id, 404);

        $data = $request->validate([
            'employee_id' => ['required', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $task->organization_id)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $issue->escalations()->create($data + [
            'organization_id' => $task->organization_id,
            'raised_by'       => $request->user()->employeeId(),
        ]);

        $this->notifier->escalated($task, $issue, $data['employee_id'], $request->user()->employeeId());

        return response()->json(['data' => $this->row($task->fresh(self::DETAIL))]);
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

            /*
             * Whether somebody else can cover this while its owner is away.
             *
             * Null is the meaningful default — follow the rule for this kind of
             * work. Set it only to disagree, which is the case the category
             * cannot decide: a self-raised task might be personal admin or the
             * one thing holding up a delivery.
             */
            'handover_policy' => ['nullable', Rule::in(Task::HANDOVER_POLICIES)],

            // Who signs it off. Without a name, `requires_approval` leaves the
            // task waiting on nobody in particular.
            'approver_id' => ['nullable', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $orgId)],

            /*
             * Who owes the work.
             *
             * The party and its link have to agree — the legacy's free-text
             * owner produced "Versa-Fatih", an organisation and a person in one
             * field, and 974 rows left blank. Naming a customer is what makes
             * "everything still waiting on Safaricom" answerable at all.
             */
            'owner_party' => ['sometimes', Rule::in(Task::PARTIES)],
            'owner_account_id' => ['nullable', 'integer', $scoped('accounts')],
            'owner_vendor_partner_id' => ['nullable', 'integer', $scoped('vendor_partners')],
            'owner_contact_id' => ['nullable', 'integer',
                Rule::exists('account_contacts', 'id')->where('organization_id', $orgId)],
            // Free text, because a client's engineer is rarely on file before
            // the task naming them exists. 95 of the legacy's 104 were this.
            'owner_contact_name' => ['nullable', 'string', 'max:255'],

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

    /**
     * The owning party and its link must agree, and the leftovers are cleared.
     *
     * Switching a task back to ours has to drop the customer it used to be
     * owed by, or a later filter finds it under an organisation that no longer
     * owes anything — which is the sort of quiet wrongness the legacy's blank
     * owner column produced 974 times.
     */
    private function reconcileOwnership(array $data, Task $task): array
    {
        if (! array_key_exists('owner_party', $data)) {
            return $data;
        }

        $party = $data['owner_party'];

        if ($party === 'customer' && ! ($data['owner_account_id'] ?? $task->owner_account_id)) {
            throw ValidationException::withMessages([
                'owner_account_id' => 'Say which customer owes this.',
            ]);
        }

        if ($party === 'vendor' && ! ($data['owner_vendor_partner_id'] ?? $task->owner_vendor_partner_id)) {
            throw ValidationException::withMessages([
                'owner_vendor_partner_id' => 'Say which vendor owes this.',
            ]);
        }

        if ($party === 'internal') {
            return $data + [
                'owner_account_id' => null, 'owner_vendor_partner_id' => null,
                'owner_contact_id' => null, 'owner_contact_name' => null,
            ];
        }

        // A customer-owned task has no vendor, and the reverse.
        return $data + ($party === 'customer'
            ? ['owner_vendor_partner_id' => null]
            : ['owner_account_id' => null, 'owner_contact_id' => null]);
    }

    private function row(Task $t): array
    {
        return [
            'id'        => $t->id,
            'reference' => $t->reference,
            'title'     => $t->title,
            'description' => $t->description,
            'category'  => $t->category,
            // So a generated task reads as one. Deleting it by hand only means
            // the schedule raises it again.
            'source'       => $t->source,
            'is_generated' => $t->is_generated,
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
            'approval_note'     => $t->approval_note,

            /*
             * Whether the person reading this is the one who can decide.
             *
             * Answered here rather than by the client comparing ids, so the
             * rule lives in one place — the client showing an Approve button
             * the server will refuse is worse than not showing it at all.
             */
            'can_approve' => $t->status === 'awaiting_approval'
                && $t->approver_id !== null
                && $t->approver_id === request()->user()?->employeeId(),
            'approver' => $t->relationLoaded('approver') && $t->approver
                ? ['id' => $t->approver->id, 'name' => $t->approver->full_name] : null,

            // Who actually picked it up and who actually finished it — not the
            // same questions as who owns it once several people are on a task.
            'started_by' => $t->relationLoaded('starter') && $t->starter
                ? ['id' => $t->starter->id, 'name' => $t->starter->full_name] : null,
            'completed_by' => $t->relationLoaded('completer') && $t->completer
                ? ['id' => $t->completer->id, 'name' => $t->completer->full_name] : null,

            'assignee' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->full_name] : null,

            /*
             * Cover, where somebody is holding it while its owner is away.
             *
             * The owner never changes, so it always comes back — a fortnight's
             * absence should not quietly move a task for good.
             */
            'covered_by' => $t->relationLoaded('activeHandover') && $t->activeHandover?->coverer
                ? [
                    'id'   => $t->activeHandover->coverer->id,
                    'name' => $t->activeHandover->coverer->full_name,
                    'until' => $t->activeHandover->ends_on?->toDateString(),
                ]
                : null,

            'is_transferable' => $t->is_transferable,

            /*
             * Who owes the work. `is_ours` is the one the rest of the system
             * should read — an externally owned task sits in somebody's list
             * here because they chase it, not because they do it.
             */
            'owner' => [
                'party'   => $t->owner_party,
                'is_ours' => $t->is_ours,
                'name'    => $t->owner_name,
                'account_id' => $t->owner_account_id,
                'vendor_partner_id' => $t->owner_vendor_partner_id,
                'contact_id'   => $t->owner_contact_id,
                'contact_name' => $t->owner_contact_name_resolved,
            ],

            /*
             * The avatar stack in the designs. The owner leads it, then anyone
             * else on the task — one accountable person, several hands.
             */
            'assignees' => $t->relationLoaded('members')
                ? collect([$t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->full_name] : null])
                    ->concat($t->members->where('capacity', 'assignee')
                        ->map(fn ($m) => ['id' => $m->employee?->id, 'name' => $m->employee?->full_name]))
                    ->filter()->unique('id')->values()
                : [],

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

            'dependencies' => $t->relationLoaded('dependencies')
                ? $t->dependencies->map(fn ($d) => [
                    'id' => $d->id, 'reference' => $d->reference, 'title' => $d->title,
                    'status' => $d->status, 'deadline' => $d->deadline?->toDateString(),
                    // A note-only blocker points back at the task itself; what
                    // matters is the note, not the phantom link.
                    'kind' => $d->pivot->kind ?? 'task',
                    'note' => $d->pivot->note ?? null,
                    'assignee' => $d->assigned_to ? ['id' => $d->assigned_to] : null,
                ])->values()
                : [],

            // Blockers with nothing here to point at. Shown beside the task
            // links because to the person reading they are the same list.
            'external_dependencies' => $t->relationLoaded('externalDependencies')
                ? $t->externalDependencies->map(fn ($d) => ['id' => $d->id, 'note' => $d->note])->values()
                : [],

            'attachments' => $t->relationLoaded('attachments')
                ? $t->attachments->map(fn ($a) => [
                    'id' => $a->id, 'label' => $a->label,
                    'original_name' => $a->original_name,
                    'size_bytes' => $a->size_bytes, 'mime_type' => $a->mime_type,
                ])->values()
                : [],

            'comments' => $t->relationLoaded('comments')
                // Pinned first, then oldest to newest, so a conversation still
                // reads in order underneath whatever was pinned above it.
                ? $t->comments->sortByDesc('is_pinned')->map(fn ($c) => $this->commentPayload($c))->values()
                : [],

            'issues' => $t->relationLoaded('issues')
                ? $t->issues->map(fn ($i) => $this->issuePayload($i))->values()
                : [],

            /*
             * Unresolved issues, counted separately from the task's status.
             * A task can be in progress and still be stuck, and the legacy left
             * 135 of its 242 issues open — that number is the whole point.
             */
            'open_issue_count' => $t->relationLoaded('issues')
                ? $t->issues->where('status', 'open')->count()
                : 0,
        ];
    }

    private function commentPayload(ProjectComment $c): array
    {
        return [
            'id'        => $c->id,
            'body'      => $c->body,
            'is_pinned' => (bool) $c->is_pinned,
            'created_at' => $c->created_at?->toIso8601String(),
            'author' => $c->relationLoaded('employee') && $c->employee
                ? ['id' => $c->employee->id, 'name' => $c->employee->full_name] : null,

            // Tallied here rather than stored, so a count cannot drift from the
            // rows it is meant to describe.
            'reactions' => $c->relationLoaded('reactions')
                ? $c->reactions->groupBy('emoji')->map->count()
                : (object) [],

            'replies' => $c->relationLoaded('replies')
                ? $c->replies->map(fn ($r) => $this->commentPayload($r))->values()
                : [],
        ];
    }

    private function issuePayload(TaskIssue $i): array
    {
        return [
            'id'       => $i->id,
            'name'     => $i->name,
            'details'  => $i->details,
            'priority' => $i->priority,
            'status'   => $i->status,
            'is_open'  => $i->is_open,
            'resolved_at' => $i->resolved_at?->toIso8601String(),
            'raised_by' => $i->relationLoaded('raiser') && $i->raiser
                ? ['id' => $i->raiser->id, 'name' => $i->raiser->full_name] : null,

            // Who it has been put in front of, in order. Kept as a list because
            // an issue that climbs past one person has two of these.
            'escalations' => $i->relationLoaded('escalations')
                ? $i->escalations->map(fn ($e) => [
                    'id' => $e->id, 'note' => $e->note,
                    'at' => $e->created_at?->toIso8601String(),
                    'to' => $e->relationLoaded('employee') && $e->employee
                        ? ['id' => $e->employee->id, 'name' => $e->employee->full_name] : null,
                ])->values()
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
