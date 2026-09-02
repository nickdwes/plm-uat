<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectAttachment;
use App\Models\Project\ProjectContractMilestone;
use App\Models\Project\ProjectMilestoneLink;
use App\Models\Organization;
use App\Models\Task\Task;
use App\Support\Account\ReferenceSequence;
use App\Support\Storage\DocumentVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Contract milestones — what the customer agreed to pay, and for what.
 *
 * Every figure here is commercial and gated the same way a deal's is. A project
 * screen is not a way round the elevation, and a milestone value is exactly the
 * number the elevation exists to protect.
 */
class ProjectMilestoneController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $milestones = $project->contractMilestones()->with($this->relations())->get();
        $shows = $this->showsCommercials($request, $project);

        return response()->json([
            'commercials_visible' => $shows,

            /*
             * Totals by currency, not one sum. 15 of the legacy's 82 milestones
             * were USD, so adding them together would state a figure that means
             * nothing — and the whole point of a receivables number is that it
             * is trustworthy.
             */
            'totals' => $shows ? $this->totals($milestones) : null,

            'data' => $milestones->map(fn ($m) => $this->payload($m, $shows)),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        [$data, $taskIds, $phaseIds] = $this->rules($request, $project);

        $milestone = $project->contractMilestones()->create($data + [
            'reference'       => ReferenceSequence::next('MS', 'project_contract_milestones', $project->organization_id),
            'organization_id' => $project->organization_id,
            // The organisation's own currency unless somebody says otherwise,
            // so a tenant billing in KES does not type it eighty-two times.
            'currency'        => $data['currency'] ?? $this->defaultCurrency($project),
            'created_by'      => $request->user()->id,
        ]);

        $this->syncLinks($milestone, $taskIds ?? [], $phaseIds ?? []);

        return response()->json([
            'data' => $this->payload($milestone->load($this->relations()), $this->showsCommercials($request, $project)),
        ], 201);
    }

    public function update(Request $request, Project $project, ProjectContractMilestone $milestone): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($milestone->project_id === $project->id, 404);

        [$data, $taskIds, $phaseIds] = $this->rules($request, $project);
        $milestone->update($data);

        // Only touch the requirement set when the caller sent one, so a plain
        // edit does not silently clear the links.
        if ($taskIds !== null || $phaseIds !== null) {
            $this->syncLinks($milestone, $taskIds ?? [], $phaseIds ?? []);
        }

        return response()->json([
            'data' => $this->payload($milestone->fresh($this->relations()), $this->showsCommercials($request, $project)),
        ]);
    }

    public function destroy(Project $project, ProjectContractMilestone $milestone): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($milestone->project_id === $project->id, 404);

        // Soft-deleted: a milestone removed from the screen was still part of
        // what was agreed, and an invoice may already refer to it.
        $milestone->delete();

        return response()->json(['message' => 'Milestone removed.']);
    }

    /**
     * Mark it invoiced or paid.
     *
     * Their own action rather than a status field on the edit form, because
     * each stamps a time — and a status that disagrees with its timestamp is
     * the thing that makes a receivables report untrustworthy.
     */
    public function transition(Request $request, Project $project, ProjectContractMilestone $milestone): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($milestone->project_id === $project->id, 404);

        $data = $request->validate([
            'status'            => ['required', Rule::in(ProjectContractMilestone::STATUSES)],
            'invoice_reference' => ['nullable', 'string', 'max:120'],
        ]);

        // Finance cannot invoice work that is not done or not evidenced. The
        // guard is only on the step into 'invoiced' — a dispute or a waiver is
        // exactly when you step around it.
        if ($data['status'] === 'invoiced') {
            $milestone->load($this->relations());

            if ($milestone->requirements_met === false) {
                return response()->json([
                    'message' => 'The linked work is not complete, so this milestone is not ready to invoice.',
                ], 422);
            }
            if (! $milestone->has_proof) {
                return response()->json([
                    'message' => 'Attach proof of delivery before invoicing this milestone.',
                ], 422);
            }
        }

        $milestone->update([
            'status'            => $data['status'],
            'invoice_reference' => $data['invoice_reference'] ?? $milestone->invoice_reference,

            // Stamped when it happens and cleared when it un-happens, so the
            // dates can never contradict the status.
            'invoiced_at' => in_array($data['status'], ['invoiced', 'paid'], true)
                ? ($milestone->invoiced_at ?? now())
                : null,
            'paid_at' => $data['status'] === 'paid' ? ($milestone->paid_at ?? now()) : null,
        ]);

        return response()->json([
            'data' => $this->payload($milestone->fresh($this->relations()), $this->showsCommercials($request, $project)),
        ]);
    }

    // ── Proof of delivery ───────────────────────────────────────────────────

    public function storeProof(Request $request, Project $project, ProjectContractMilestone $milestone): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($milestone->project_id === $project->id, 404);

        $data = $request->validate([
            'file'  => ['required', 'file', 'max:20480',
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg,zip'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');

        $project->attachments()->create([
            'organization_id' => $project->organization_id,
            'milestone_id'    => $milestone->id,
            'document_type'   => 'proof',
            'label'           => ($data['label'] ?? null) ?: $file->getClientOriginalName(),
            'original_name'   => $file->getClientOriginalName(),
            'storage_path'    => DocumentVault::store($file, 'project_documents', "projects/{$project->id}/milestones/{$milestone->id}"),
            'mime_type'       => $file->getClientMimeType(),
            'size_bytes'      => $file->getSize(),
            'uploaded_by'     => $request->user()->id,
        ]);

        return response()->json([
            'data' => $this->payload($milestone->fresh($this->relations()), $this->showsCommercials($request, $project)),
        ], 201);
    }

    public function downloadProof(Project $project, ProjectContractMilestone $milestone, ProjectAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $project);
        abort_unless($milestone->project_id === $project->id, 404);
        abort_unless($attachment->milestone_id === $milestone->id, 404);

        return DocumentVault::download(
            'project_documents', $attachment->storage_path,
            $attachment->original_name, $attachment->mime_type,
        );
    }

    public function destroyProof(Request $request, Project $project, ProjectContractMilestone $milestone, ProjectAttachment $attachment): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($milestone->project_id === $project->id, 404);
        abort_unless($attachment->milestone_id === $milestone->id, 404);

        $attachment->delete();

        return response()->json([
            'data' => $this->payload($milestone->fresh($this->relations()), $this->showsCommercials($request, $project)),
        ]);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /** Everything the payload reads, loaded once. */
    private function relations(): array
    {
        return ['task', 'links.task:id,title,status', 'links.phase', 'proofs'];
    }

    /** Replace a milestone's requirement links with the given tasks and phases. */
    private function syncLinks(ProjectContractMilestone $milestone, array $taskIds, array $phaseIds): void
    {
        $milestone->links()->delete();

        $rows = [];
        foreach (array_unique($taskIds) as $taskId) {
            $rows[] = ['organization_id' => $milestone->organization_id, 'task_id' => $taskId, 'phase_id' => null];
        }
        foreach (array_unique($phaseIds) as $phaseId) {
            $rows[] = ['organization_id' => $milestone->organization_id, 'task_id' => null, 'phase_id' => $phaseId];
        }

        if ($rows) {
            $milestone->links()->createMany($rows);
        }
    }

    /**
     * @return array{0: array<string,mixed>, 1: ?array<int>, 2: ?array<int>}
     *   the milestone attributes, the required task ids, the required phase ids.
     *   The id arrays are null when the caller sent no key at all — the signal
     *   an update uses to leave the requirement set untouched.
     */
    private function rules(Request $request, Project $project): array
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'value'       => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            // Three-letter ISO. 15 of the legacy's 82 were USD, so this is not
            // hypothetical.
            'currency'    => ['sometimes', 'string', 'size:3'],
            'due_on'      => ['nullable', 'date'],
            'notes'       => ['nullable', 'string', 'max:20000'],

            // Legacy single "paid against" task, kept so older callers and the
            // work_is_done flag still work. New callers use task_ids/phase_ids.
            'task_id' => ['nullable', 'integer',
                Rule::exists('tasks', 'id')->where('project_id', $project->id)],

            // The work that must be done before it is billable — any number of
            // tasks and/or phases, all scoped to this project so a milestone
            // cannot be gated on somebody else's work.
            'task_ids'    => ['sometimes', 'array'],
            'task_ids.*'  => ['integer', Rule::exists('tasks', 'id')->where('project_id', $project->id)],
            'phase_ids'   => ['sometimes', 'array'],
            'phase_ids.*' => ['integer', Rule::exists('project_phases', 'id')->where('project_id', $project->id)],
        ]);

        $taskIds  = $request->has('task_ids') ? array_values($validated['task_ids'] ?? []) : null;
        $phaseIds = $request->has('phase_ids') ? array_values($validated['phase_ids'] ?? []) : null;

        unset($validated['task_ids'], $validated['phase_ids']);

        return [$validated, $taskIds, $phaseIds];
    }

    /**
     * The organisation's own currency, so a tenant billing in KES does not
     * type it eighty-two times. Falls back rather than failing: a missing
     * currency should not stop a milestone being recorded.
     */
    private function defaultCurrency(Project $project): string
    {
        return Organization::whereKey($project->organization_id)->value('currency') ?: 'KES';
    }

    private function showsCommercials(Request $request, Project $project): bool
    {
        /*
         * Gated through the deal the project came from. A project with no
         * opportunity has no deal to check, so it falls back to the project's
         * own permissions rather than hiding figures nobody can ever unhide.
         */
        $project->loadMissing('opportunity');

        if ($project->opportunity === null) {
            return true;
        }

        return $request->user()?->can('viewCommercials', $project->opportunity) ?? false;
    }

    private function totals($milestones): array
    {
        return $milestones->groupBy('currency')->map(fn ($group) => [
            'agreed'      => (float) $group->sum('value'),
            'invoiced'    => (float) $group->whereIn('status', ['invoiced', 'paid'])->sum('value'),
            'paid'        => (float) $group->where('status', 'paid')->sum('value'),
            // Invoiced and unpaid — the money actually owed.
            'outstanding' => (float) $group->where('status', 'invoiced')->sum('value'),
        ])->all();
    }

    private function payload(ProjectContractMilestone $m, bool $showsMoney): array
    {
        return [
            'id'          => $m->id,
            'reference'   => $m->reference,
            'name'        => $m->name,
            'description' => $m->description,
            'status'      => $m->status,

            // Withheld as null rather than omitted, so a masked figure and an
            // unrecorded one cannot look the same.
            'currency' => $showsMoney ? $m->currency : null,
            'value'    => $showsMoney ? $m->value : null,

            'due_on'      => $m->due_on?->toDateString(),
            'invoiced_at' => $m->invoiced_at?->toIso8601String(),
            'paid_at'     => $m->paid_at?->toIso8601String(),
            'invoice_reference' => $m->invoice_reference,

            'is_paid'        => $m->is_paid,
            'is_outstanding' => $m->is_outstanding,
            'is_overdue'     => $m->is_overdue,

            /*
             * Whether the work is finished. The reason a milestone points at a
             * task at all — billing for something not yet delivered is a
             * conversation nobody wants to have with the customer first.
             */
            'work_is_done' => $m->work_is_done,

            'task' => $m->task ? [
                'id' => $m->task->id, 'title' => $m->task->title, 'status' => $m->task->status,
            ] : null,

            // What must be complete before it is billable, and where each stands.
            'requirements'     => $this->requirements($m),
            'requirements_met' => $m->requirements_met,

            // Proof of delivery — what finance invoices against.
            'proofs' => $m->proofs->map(fn (ProjectAttachment $a) => [
                'id' => $a->id, 'label' => $a->label,
                'size_bytes' => $a->size_bytes, 'uploaded_at' => $a->created_at?->toDateTimeString(),
            ])->values(),
            'has_proof'         => $m->has_proof,

            // Still pending, work done, proof attached — finance can raise it.
            'ready_to_invoice'  => $m->ready_to_invoice,
        ];
    }

    /** Each linked task/phase resolved to a title and whether it is complete. */
    private function requirements(ProjectContractMilestone $m): array
    {
        return $m->links->map(function (ProjectMilestoneLink $link) {
            if ($link->task) {
                return [
                    'type'  => 'task', 'id' => $link->task->id, 'title' => $link->task->title,
                    'status' => $link->task->status, 'done' => $link->task->status === Task::DONE,
                ];
            }
            if ($link->phase) {
                $done = $link->phase->isComplete();
                return [
                    'type'  => 'phase', 'id' => $link->phase->id, 'title' => $link->phase->name,
                    'status' => $done ? 'complete' : 'in_progress', 'done' => $done,
                ];
            }

            return null; // target deleted
        })->filter()->values()->all();
    }
}
