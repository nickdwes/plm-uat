<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectAttachment;
use App\Models\Project\ProjectRisk;
use App\Support\Storage\DocumentVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectRiskController extends Controller
{
    private const WITH = ['owner:id,first_name,last_name', 'task:id,title,status', 'phase:id,name', 'evidence'];

    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $risks = $project->risks()->with(self::WITH)->get();

        // Hottest open risks first; closed ones sink.
        $sorted = $risks->sortBy(fn (ProjectRisk $r) => [
            $r->status === ProjectRisk::STATUS_CLOSED ? 1 : 0,
            -$r->score(),
        ])->values();

        return response()->json([
            'data' => $sorted->map(fn (ProjectRisk $r) => $this->payload($r)),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $this->validated($request, $project, creating: true);

        $risk = $project->risks()->create($data + [
            'organization_id' => $project->organization_id,
        ]);

        return response()->json(['data' => $this->payload($risk->load(self::WITH))], 201);
    }

    public function update(Request $request, Project $project, ProjectRisk $risk): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($risk->project_id === $project->id, 404);

        $risk->update($this->validated($request, $project, creating: false));

        return response()->json(['data' => $this->payload($risk->fresh(self::WITH))]);
    }

    public function destroy(Project $project, ProjectRisk $risk): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($risk->project_id === $project->id, 404);

        $risk->delete();

        return response()->json(['message' => 'Risk removed.']);
    }

    // ── Evidence ────────────────────────────────────────────────────────────

    public function storeEvidence(Request $request, Project $project, ProjectRisk $risk): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($risk->project_id === $project->id, 404);

        $data = $request->validate([
            'file'  => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg,zip'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');

        $project->attachments()->create([
            'organization_id' => $project->organization_id,
            'risk_id'         => $risk->id,
            'document_type'   => 'evidence',
            'label'           => ($data['label'] ?? null) ?: $file->getClientOriginalName(),
            'original_name'   => $file->getClientOriginalName(),
            'storage_path'    => DocumentVault::store($file, 'project_documents', "projects/{$project->id}/risks/{$risk->id}"),
            'mime_type'       => $file->getClientMimeType(),
            'size_bytes'      => $file->getSize(),
            'uploaded_by'     => $request->user()->id,
        ]);

        return response()->json(['data' => $this->payload($risk->fresh(self::WITH))], 201);
    }

    public function downloadEvidence(Project $project, ProjectRisk $risk, ProjectAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $project);
        abort_unless($risk->project_id === $project->id && $attachment->risk_id === $risk->id, 404);

        return DocumentVault::download(
            'project_documents', $attachment->storage_path,
            $attachment->original_name, $attachment->mime_type,
        );
    }

    public function destroyEvidence(Project $project, ProjectRisk $risk, ProjectAttachment $attachment): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($risk->project_id === $project->id && $attachment->risk_id === $risk->id, 404);

        $attachment->delete();

        return response()->json(['data' => $this->payload($risk->fresh(self::WITH))]);
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function validated(Request $request, Project $project, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title'       => [$req, 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:5000'],
            'likelihood'  => ['sometimes', Rule::in(ProjectRisk::LEVELS)],
            'impact'      => ['sometimes', Rule::in(ProjectRisk::LEVELS)],
            'status'      => ['sometimes', Rule::in(ProjectRisk::STATUSES)],
            'mitigation'  => ['nullable', 'string', 'max:5000'],
            'owner_employee_id' => ['nullable', 'integer', 'exists:employees,id'],

            // The work this risk bears on — scoped to this project.
            'task_id'  => ['nullable', 'integer', Rule::exists('tasks', 'id')->where('project_id', $project->id)],
            'phase_id' => ['nullable', 'integer', Rule::exists('project_phases', 'id')->where('project_id', $project->id)],
        ]);
    }

    private function payload(ProjectRisk $r): array
    {
        return [
            'id'          => $r->id,
            'title'       => $r->title,
            'description' => $r->description,
            'likelihood'  => $r->likelihood,
            'impact'      => $r->impact,
            'score'       => $r->score(),
            'status'      => $r->status,
            'mitigation'  => $r->mitigation,
            'owner'       => $r->relationLoaded('owner') && $r->owner
                ? ['id' => $r->owner->id, 'name' => trim($r->owner->first_name . ' ' . $r->owner->last_name)] : null,

            'task_id'  => $r->task_id,
            'phase_id' => $r->phase_id,
            'task'     => $r->relationLoaded('task') && $r->task ? ['id' => $r->task->id, 'title' => $r->task->title, 'status' => $r->task->status] : null,
            'phase'    => $r->relationLoaded('phase') && $r->phase ? ['id' => $r->phase->id, 'name' => $r->phase->name] : null,
            'evidence' => $r->relationLoaded('evidence') ? $r->evidence->map(fn (ProjectAttachment $a) => [
                'id' => $a->id, 'label' => $a->label, 'size_bytes' => $a->size_bytes,
            ])->values() : [],

            'created_at'  => $r->created_at?->toDateTimeString(),
        ];
    }
}
