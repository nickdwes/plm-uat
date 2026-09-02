<?php

namespace App\Services\Task;

use App\Models\Asset\Asset;
use App\Models\Task\Task;
use App\Models\Task\TaskLogistics;
use App\Models\Task\TaskPrerequisite;

/**
 * Turns a task's logistics flags into tracked prerequisite actions.
 *
 * Called whenever logistics are saved. It reconciles the checklist to the flags
 * — adding what is newly needed, dropping what is no longer flagged (unless work
 * has started on it), and leaving in-progress items alone. Asset and licence
 * items get a live read of the inventory so the PM sees at a glance whether the
 * thing exists to allocate or has to be procured. Nothing is written to other
 * modules here — that is a later phase, keyed off `linked_*`.
 */
class TaskPrerequisiteSync
{
    public function sync(Task $task, TaskLogistics $logistics, ?int $actorId = null): void
    {
        $desired = $this->desiredTypes($logistics);

        $existing = $task->prerequisites()->get()->keyBy('type');

        // Drop prerequisites whose flag was cleared — but keep any a person has
        // already started or finished, as a record of what was arranged.
        foreach ($existing as $type => $row) {
            if (! isset($desired[$type]) && $row->status === TaskPrerequisite::STATUS_OPEN
                && ! $row->resolved_at && ! $row->linked_id) {
                $row->delete();
            }
        }

        // Travel is reminded five days before the task starts.
        $travelDue = $task->started_on ? $task->started_on->copy()->subDays(5)->toDateString() : null;

        foreach ($desired as $type => $label) {
            if (isset($existing[$type])) {
                // Keep its status; only refresh the live inventory read.
                if (in_array($type, ['asset', 'software'], true)) {
                    [$detail, $procurement] = $this->inventoryCheck($task->organization_id, $type);
                    $existing[$type]->update(['detail' => $detail, 'procurement_needed' => $procurement]);
                }
                if ($type === 'travel' && $travelDue && ! $existing[$type]->due_on) {
                    $existing[$type]->update(['due_on' => $travelDue]);
                }
                continue;
            }

            $attrs = [
                'organization_id' => $task->organization_id,
                'project_id'      => $task->project_id,
                'type'            => $type,
                'label'           => $label,
                'status'          => TaskPrerequisite::STATUS_OPEN,
                'created_by'      => $actorId,
            ];

            if (in_array($type, ['asset', 'software'], true)) {
                [$attrs['detail'], $attrs['procurement_needed']] = $this->inventoryCheck($task->organization_id, $type);
            }
            if ($type === 'travel') {
                $attrs['due_on'] = $travelDue;
            }

            // An approval routes to whoever holds the approving governance seat.
            if ($type === 'approval' && $task->project_id) {
                $approver = app(\App\Services\Project\GovernanceApprovalRouter::class)->approverFor($task->project_id);
                if ($approver) {
                    $attrs['detail'] = "Routed to {$approver['name']} — {$approver['role_label']}.";
                    $this->notifyApprover($task, $approver);
                } else {
                    $attrs['detail'] = 'No governance approver assigned — assign a Project Manager, Director or Executive Sponsor.';
                }
            }

            $task->prerequisites()->create($attrs);
        }
    }

    /** Notify the resolved governance approver that a task awaits their sign-off. */
    private function notifyApprover(Task $task, array $approver): void
    {
        if (! $approver['user_id']) {
            return;
        }

        app(\App\Services\NotificationService::class)->create(
            (string) $task->organization_id,
            $approver['user_id'],
            'task.approval_routed',
            'Approval needed',
            "“{$task->title}” has a prerequisite that needs your sign-off as {$approver['role_label']}.",
            ['task_id' => $task->id, 'project_id' => $task->project_id],
            \App\Models\Task\Task::class,
            (string) $task->id,
        );
    }

    /** @return array<string,string> desired prerequisite type => label */
    private function desiredTypes(TaskLogistics $logistics): array
    {
        $desired = [];
        foreach (TaskPrerequisite::FROM_FLAG as $flag => [$type, $label]) {
            if ($logistics->{$flag}) {
                $desired[$type] = $label;
            }
        }
        if ($logistics->requires_approval) {
            $desired['approval'] = 'Approvals';
        }

        return $desired;
    }

    /**
     * A read-only look at the inventory: is there one to allocate, or does it
     * have to be procured? Physical/equipment counts available assets; software
     * counts spare licence seats.
     *
     * @return array{0:string,1:bool} [detail, procurement_needed]
     */
    private function inventoryCheck(int $orgId, string $type): array
    {
        if ($type === 'asset') {
            $available = Asset::where('organization_id', $orgId)
                ->where('status', Asset::STATUS_AVAILABLE)->count();

            return $available > 0
                ? ["{$available} available in inventory — allocate one.", false]
                : ['None available in inventory — procurement needed.', true];
        }

        // software / licence seats
        $spare = Asset::where('organization_id', $orgId)
            ->whereIn('asset_class', Asset::SEATED_CLASSES)
            ->get()
            ->sum(fn (Asset $a) => max(0, $a->seatsSpare() ?? 0));

        return $spare > 0
            ? ["{$spare} licence seat(s) available — allocate one.", false]
            : ['No spare licence seats — procurement needed.', true];
    }
}
