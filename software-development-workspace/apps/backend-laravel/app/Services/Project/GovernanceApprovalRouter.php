<?php

namespace App\Services\Project;

use App\Models\Project\ProjectGovernanceRole;

/**
 * Routes approvals and escalations by governance role ownership.
 *
 * "Who approves this?" is answered by whoever holds the approving seat on the
 * project's governance committee — the Project Manager, or up the chain to the
 * Director and Executive Sponsor when the seat below is empty. The escalation
 * order is the committee's own seniority.
 */
class GovernanceApprovalRouter
{
    /** Approving seats, in order of who is asked first. */
    private const APPROVER_CHAIN = ['project_manager', 'project_director', 'executive_sponsor'];

    /**
     * The person an approval on this project routes to — the first filled seat
     * in the approver chain. Null when none of those seats has anybody in it.
     *
     * @return array{employee_id:int, name:string, role_label:string, user_id:?int}|null
     */
    public function approverFor(int $projectId): ?array
    {
        $rows = ProjectGovernanceRole::where('project_id', $projectId)
            ->whereIn('role_key', self::APPROVER_CHAIN)
            ->whereNotNull('assignee_employee_id')
            ->with('assignee:id,first_name,last_name,user_id')
            ->get()->keyBy('role_key');

        foreach (self::APPROVER_CHAIN as $key) {
            $row = $rows->get($key);
            if ($row && $row->assignee) {
                return [
                    'employee_id' => $row->assignee->id,
                    'name'        => trim($row->assignee->first_name . ' ' . $row->assignee->last_name),
                    'role_label'  => ProjectGovernanceRole::CATALOG[$key] ?? $key,
                    'user_id'     => $row->assignee->user_id,
                ];
            }
        }

        return null;
    }
}
