<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectContractMilestone;
use App\Models\Organization;
use App\Support\Account\ReferenceSequence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

        $milestones = $project->contractMilestones()->with('task')->get();
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

        $data = $this->rules($request, $project);

        $milestone = $project->contractMilestones()->create($data + [
            'reference'       => ReferenceSequence::next('MS', 'project_contract_milestones', $project->organization_id),
            'organization_id' => $project->organization_id,
            // The organisation's own currency unless somebody says otherwise,
            // so a tenant billing in KES does not type it eighty-two times.
            'currency'        => $data['currency'] ?? $this->defaultCurrency($project),
            'created_by'      => $request->user()->id,
        ]);

        return response()->json([
            'data' => $this->payload($milestone->load('task'), $this->showsCommercials($request, $project)),
        ], 201);
    }

    public function update(Request $request, Project $project, ProjectContractMilestone $milestone): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($milestone->project_id === $project->id, 404);

        $milestone->update($this->rules($request, $project));

        return response()->json([
            'data' => $this->payload($milestone->fresh('task'), $this->showsCommercials($request, $project)),
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
            'data' => $this->payload($milestone->fresh('task'), $this->showsCommercials($request, $project)),
        ]);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function rules(Request $request, Project $project): array
    {
        return $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'value'       => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            // Three-letter ISO. 15 of the legacy's 82 were USD, so this is not
            // hypothetical.
            'currency'    => ['sometimes', 'string', 'size:3'],
            'due_on'      => ['nullable', 'date'],
            'notes'       => ['nullable', 'string', 'max:20000'],

            // Scoped to this project: a milestone cannot be paid against
            // somebody else's work.
            'task_id' => ['nullable', 'integer',
                Rule::exists('tasks', 'id')->where('project_id', $project->id)],
        ]);
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
        ];
    }
}
