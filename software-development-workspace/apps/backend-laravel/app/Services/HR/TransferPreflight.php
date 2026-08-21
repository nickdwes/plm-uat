<?php

namespace App\Services\HR;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What is still holding somebody in the organisation they are leaving.
 *
 * A transfer is not a column flip. Around twenty-five places in this
 * application validate an assignee with
 * `Rule::exists('employees','id')->where('primary_organization_id', $orgId)` —
 * tasks, projects, opportunities, leads, accounts, attendance, interviews. The
 * moment somebody's primary organisation changes, **every row in their old
 * organisation that names them becomes un-resaveable**. Somebody opens a task
 * three weeks later, changes the due date, and is told the assignee is invalid
 * with no explanation of why.
 *
 * So the order is reassign, then move. This lists what to reassign.
 *
 * Nothing here is moved automatically. Every item is a decision — whether the
 * laptop goes with them, whether the customer keeps the same engineer, whether
 * a half-recovered loan follows — and a process that guesses at those is one
 * that gets switched off the first time it guesses wrong.
 */
class TransferPreflight
{
    /**
     * @return array{blocking: list<array>, warnings: list<array>, clear: bool}
     */
    public function run(Employee $employee, int $fromOrganizationId): array
    {
        $items = array_merge(
            $this->work($employee, $fromOrganizationId),
            $this->kit($employee, $fromOrganizationId),
            $this->serviceDesk($employee, $fromOrganizationId),
            $this->people($employee, $fromOrganizationId),
            $this->money($employee, $fromOrganizationId),
        );

        $items = array_values(array_filter($items, fn ($i) => $i['count'] > 0));

        $blocking = array_values(array_filter($items, fn ($i) => $i['blocking']));

        return [
            'blocking' => $blocking,
            'warnings' => array_values(array_filter($items, fn ($i) => ! $i['blocking'])),
            'clear'    => $blocking === [],
        ];
    }

    // ── Work in flight ────────────────────────────────────────────────────────

    private function work(Employee $employee, int $orgId): array
    {
        return [
            $this->item(
                'open_tasks',
                'Open tasks assigned to them',
                $this->count('tasks', fn ($q) => $q
                    ->where('organization_id', $orgId)
                    ->where('assigned_to', $employee->id)
                    ->whereNotIn('status', ['completed'])
                    ->whereNull('deleted_at')),
                'These stay in the old organisation and cannot be re-saved once the move lands — '
                . 'the assignee will fail validation.',
            ),
            $this->item(
                'recurring_tasks',
                'Recurring task schedules pointed at them',
                $this->count('task_recurrences', fn ($q) => $q
                    ->where('organization_id', $orgId)
                    ->where('assigned_to', $employee->id)),
                'Left alone these keep generating work for somebody at another company, indefinitely.',
                blocking: true,
            ),
            $this->item(
                'project_leadership',
                'Projects they lead',
                $this->count('projects', fn ($q) => $q
                    ->where('organization_id', $orgId)
                    ->where('project_lead_id', $employee->id)
                    ->whereNull('deleted_at')),
                'A project without a lead in its own organisation has nobody to escalate to.',
            ),
        ];
    }

    // ── Things they are holding ───────────────────────────────────────────────

    private function kit(Employee $employee, int $orgId): array
    {
        return [
            $this->item(
                'assets_held',
                'Equipment signed out to them',
                $this->count('assets', fn ($q) => $q
                    ->where('organization_id', $orgId)
                    ->where(fn ($w) => $w->where('assigned_to', $employee->id)
                        ->orWhere('custodian_id', $employee->id))
                    ->whereNull('deleted_at')),
                'Nothing checks that a holder works for the organisation that owns the asset, so the '
                . 'old register will keep naming them.',
            ),
            $this->item(
                'licence_seats',
                'Licence seats allocated to them',
                $this->count('asset_seat_allocations', fn ($q) => $q
                    ->where('organization_id', $orgId)
                    ->where('employee_id', $employee->id)),
                'The old organisation keeps paying for a seat somebody else is now using.',
            ),
        ];
    }

    // ── The service desk ──────────────────────────────────────────────────────

    /**
     * Support keys off the user account, which a transfer does not touch.
     *
     * That is precisely why it needs listing: the engineer moves company and
     * their queues, their engineer profile and the customers they are named on
     * all keep working, routing a customer's tickets to somebody who no longer
     * works for the company that owes them a service level.
     */
    private function serviceDesk(Employee $employee, int $orgId): array
    {
        $userId = $employee->user_id;

        if (! $userId) {
            return [];
        }

        return [
            $this->item(
                'open_tickets',
                'Support tickets assigned to them',
                $this->count('support_tickets', fn ($q) => $q
                    ->where('organization_id', $orgId)
                    ->where('assigned_to', $userId)
                    ->whereNull('resolved_at')),
                'Support is keyed on the login, which does not move — these keep working and keep '
                . 'being theirs.',
            ),
            $this->item(
                'contract_engineer',
                'Customer contracts they are the named engineer on',
                $this->count('account_contract_engineers', fn ($q) => $q
                    ->where('organization_id', $orgId)
                    ->where('user_id', $userId)
                    ->where('is_active', true)),
                'Routing will keep sending that customer to them.',
                blocking: true,
            ),
            $this->item(
                'support_teams',
                'Service desk queues they belong to',
                $this->count('support_team_members', fn ($q) => $q
                    ->where('user_id', $userId)
                    ->whereIn('support_team_id', DB::table('support_teams')
                        ->where('organization_id', $orgId)->select('id'))),
                'Queue membership survives the move without a trace.',
            ),
        ];
    }

    // ── People who depend on them ─────────────────────────────────────────────

    private function people(Employee $employee, int $orgId): array
    {
        $leads = 0;

        // These three have no foreign key at all, so nothing will ever null
        // them and nothing will complain. They have to be looked for.
        foreach (['departments', 'org_business_units', 'org_practices'] as $table) {
            if (Schema::hasColumn($table, 'lead_employee_id')) {
                $leads += $this->count($table, fn ($q) => $q
                    ->where('organization_id', $orgId)
                    ->where('lead_employee_id', $employee->id));
            }
        }

        return [
            $this->item(
                'direct_reports',
                'People who report to them',
                $this->count('employees', fn ($q) => $q
                    ->where('primary_organization_id', $orgId)
                    ->where('reports_to', $employee->id)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
                'Everyone under them would report across a company boundary.',
                blocking: true,
            ),
            $this->item(
                'unit_leadership',
                'Departments, business units or practices they head',
                $leads,
                'These columns have no foreign key, so nothing will clear them and nothing will '
                . 'complain — the unit is simply left headed by somebody who has gone.',
                blocking: true,
            ),
            $this->item(
                'travel_approver',
                'Travel approvals routed to them',
                $this->count('travel_department_approvers', fn ($q) => $q
                    ->where('organization_id', $orgId)
                    ->where('employee_id', $employee->id)),
                'They stay a live approver for the old organisation.',
                blocking: true,
            ),
        ];
    }

    // ── Money ─────────────────────────────────────────────────────────────────

    /**
     * A half-recovered loan is the one thing this refuses to let past.
     *
     * Outstanding is *derived* from the payroll run items of approved runs —
     * there is deliberately no stored balance, so nothing carries. Re-create
     * the schedule in the new organisation and it starts from zero recovered
     * and keeps deducting until it has taken the whole principal a second time.
     * Somebody has to decide the reduced principal, and it is not this code.
     */
    private function money(Employee $employee, int $orgId): array
    {
        return [
            $this->item(
                'recurring_deductions',
                'Recurring deductions still running',
                $this->count('employee_recurring_deductions', fn ($q) => $q
                    ->where('organization_id', $orgId)
                    ->where('employee_id', $employee->id)
                    ->where('status', 'active')),
                'Outstanding balance is worked out from past payslips, so nothing carries. Re-created '
                . 'in the new organisation the schedule restarts at zero recovered and over-recovers '
                . 'past the principal. Close it here and re-open it there for what is left.',
                blocking: true,
            ),
        ];
    }

    // ── Shared ────────────────────────────────────────────────────────────────

    private function item(string $key, string $label, int $count, string $why, bool $blocking = false): array
    {
        return compact('key', 'label', 'count', 'why', 'blocking');
    }

    private function count(string $table, callable $where): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);
        $where($query);

        return $query->count();
    }
}
