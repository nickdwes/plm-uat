<?php

namespace App\Services\Task;

use App\Models\Procurement\ProcurementRequest;
use App\Models\Project\ProjectVendorEngagement;
use App\Models\Task\TaskPrerequisite;
use App\Support\Account\ReferenceSequence;

/**
 * Phase 2 of task logistics: turn a tracked prerequisite into a real record in
 * the module that owns it, and link the two so the prerequisite carries a live
 * pointer to what it raised.
 *
 * Only procurement is raised here (asset / software / procurement prerequisites
 * → a project procurement request). Travel is linked to its cycle's request
 * when the schedule is generated; vendor engagement is handled separately.
 *
 * Idempotent: a prerequisite already pointing at a live procurement request
 * gets that same record back rather than a duplicate.
 */
class PrerequisiteFulfiller
{
    /** Which prerequisite types become a procurement request, and their category. */
    private const PROCUREMENT_CATEGORY = [
        'asset'       => 'equipment',
        'software'    => 'licence',
        'procurement' => 'other',
    ];

    public function canRaiseProcurement(TaskPrerequisite $p): bool
    {
        return isset(self::PROCUREMENT_CATEGORY[$p->type]);
    }

    /**
     * Raise (or return the already-raised) project procurement request for an
     * asset / software / procurement prerequisite.
     */
    public function raiseProcurement(TaskPrerequisite $p, ?int $actorId = null): ProcurementRequest
    {
        abort_unless($this->canRaiseProcurement($p), 422, 'This prerequisite does not raise procurement.');

        $task = $p->task;
        abort_unless($task && $task->project_id, 422, 'Only a project task can raise procurement.');

        if ($existing = $this->linkedProcurement($p)) {
            return $existing;
        }

        $label = match ($p->type) {
            'asset'    => "Equipment for: {$task->title}",
            'software' => "Software / licence for: {$task->title}",
            default    => "Procurement for: {$task->title}",
        };

        $request = ProcurementRequest::create([
            'organization_id' => $task->organization_id,
            'reference'       => ReferenceSequence::next('PR', 'procurement_requests', $task->organization_id),
            'context'         => 'project',
            'project_id'      => $task->project_id,
            'task_id'         => $task->id,
            'item'            => $label,
            'category'        => self::PROCUREMENT_CATEGORY[$p->type],
            'notes'           => 'Raised from a task requirement.',
            'created_by'      => $actorId,
        ]);

        $p->update([
            'linked_type' => ProcurementRequest::class,
            'linked_id'   => $request->id,
            'status'      => $p->status === TaskPrerequisite::STATUS_OPEN ? TaskPrerequisite::STATUS_IN_PROGRESS : $p->status,
            'detail'      => "Raised procurement {$request->reference}.",
        ]);

        return $request;
    }

    /** The live procurement request a prerequisite already points at, if any. */
    private function linkedProcurement(TaskPrerequisite $p): ?ProcurementRequest
    {
        if ($p->linked_type !== ProcurementRequest::class || ! $p->linked_id) {
            return null;
        }

        return ProcurementRequest::find($p->linked_id);
    }

    // ── Vendor engagement ─────────────────────────────────────────────────────

    public function canRaiseEngagement(TaskPrerequisite $p): bool
    {
        return isset(ProjectVendorEngagement::FROM_PREREQ[$p->type]);
    }

    /**
     * Raise (or return the already-raised) vendor engagement for an
     * external-vendor / professional-services / subcontractor prerequisite.
     */
    public function raiseEngagement(TaskPrerequisite $p, ?int $actorId = null): ProjectVendorEngagement
    {
        abort_unless($this->canRaiseEngagement($p), 422, 'This prerequisite does not raise a vendor engagement.');

        $task = $p->task;
        abort_unless($task && $task->project_id, 422, 'Only a project task can raise an engagement.');

        if ($existing = $this->linkedEngagement($p)) {
            return $existing;
        }

        $engagement = ProjectVendorEngagement::create([
            'organization_id' => $task->organization_id,
            'project_id'      => $task->project_id,
            'task_id'         => $task->id,
            'reference'       => ReferenceSequence::next('VE', 'project_vendor_engagements', $task->organization_id),
            'engagement_type' => ProjectVendorEngagement::FROM_PREREQ[$p->type],
            'scope'           => "For task: {$task->title}",
            'status'          => ProjectVendorEngagement::STATUS_IDENTIFYING,
            'created_by'      => $actorId,
        ]);

        $p->update([
            'linked_type' => ProjectVendorEngagement::class,
            'linked_id'   => $engagement->id,
            'status'      => $p->status === TaskPrerequisite::STATUS_OPEN ? TaskPrerequisite::STATUS_IN_PROGRESS : $p->status,
            'detail'      => "Raised engagement {$engagement->reference}.",
        ]);

        return $engagement;
    }

    /** Dispatch to the right raise by prerequisite type. */
    public function raise(TaskPrerequisite $p, ?int $actorId = null): object
    {
        if ($this->canRaiseProcurement($p)) {
            return $this->raiseProcurement($p, $actorId);
        }
        if ($this->canRaiseEngagement($p)) {
            return $this->raiseEngagement($p, $actorId);
        }

        abort(422, 'This prerequisite has no record to raise.');
    }

    /** Whether a prerequisite can raise any kind of record. */
    public function canRaise(TaskPrerequisite $p): bool
    {
        return $this->canRaiseProcurement($p) || $this->canRaiseEngagement($p);
    }

    private function linkedEngagement(TaskPrerequisite $p): ?ProjectVendorEngagement
    {
        if ($p->linked_type !== ProjectVendorEngagement::class || ! $p->linked_id) {
            return null;
        }

        return ProjectVendorEngagement::find($p->linked_id);
    }
}
