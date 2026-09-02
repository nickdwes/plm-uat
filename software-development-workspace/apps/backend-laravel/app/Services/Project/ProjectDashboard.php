<?php

namespace App\Services\Project;

use App\Models\Project\Project;
use App\Models\Project\ProjectBudgetLine;
use App\Models\Project\ProjectContractMilestone;
use App\Models\Project\ProjectExpense;
use App\Models\Project\ProjectIssue;
use App\Models\Task\Task;
use App\Models\Task\TaskLogistics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Everything the Projects Manager Dashboard shows, in one pass.
 *
 * The old PLM walked project → tasks → issues → escalations in PHP at render
 * time. This does the same shape of roll-up but with a handful of grouped
 * queries rather than an accessor firing per row, so it stays cheap as the
 * number of projects grows.
 *
 * Task status is mapped onto the three buckets the dashboard reads —
 * pending (todo/blocked), ongoing (in_progress/awaiting_approval), completed —
 * and "overdue" is derived (deadline past and not completed), exactly as the
 * old system derived it, never a stored status.
 */
class ProjectDashboard
{
    private const PENDING = ['todo', 'blocked'];
    private const ONGOING = ['in_progress', 'awaiting_approval'];

    /**
     * @param  string|null $status  A single status to scope the per-project
     *   panels to; null means the whole active portfolio (kickoff/in_flight/on_hold).
     */
    public function build(int $organizationId, ?string $status = null): array
    {
        $today = Carbon::today()->toDateString();
        $activeStatuses = $status ? [$status] : ['kickoff', 'in_flight', 'on_hold'];

        $projects = Project::where('organization_id', $organizationId)
            ->with('projectLead:id,first_name,last_name')
            ->orderByRaw("CASE status WHEN 'in_flight' THEN 0 WHEN 'kickoff' THEN 1 WHEN 'on_hold' THEN 2 ELSE 3 END")
            ->get(['id', 'reference', 'name', 'status', 'priority', 'deadline', 'started_on', 'delivered_on', 'contract_value', 'project_lead_id', 'created_at']);

        $projectIds = $projects->pluck('id');

        // ── Task roll-ups, grouped ────────────────────────────────────────
        $taskByProjectStatus = Task::whereIn('project_id', $projectIds)
            ->selectRaw('project_id, status, COUNT(*) as n')
            ->groupBy('project_id', 'status')->get()
            ->groupBy('project_id');

        $overdueByProject = Task::whereIn('project_id', $projectIds)
            ->whereNotNull('deadline')->whereDate('deadline', '<', $today)
            ->where('status', '!=', Task::DONE)
            ->selectRaw('project_id, COUNT(*) as n')->groupBy('project_id')
            ->pluck('n', 'project_id');

        // ── Issue roll-ups ────────────────────────────────────────────────
        $issueByProjectStatus = ProjectIssue::whereIn('project_id', $projectIds)
            ->selectRaw('project_id, status, COUNT(*) as n')
            ->groupBy('project_id', 'status')->get()->groupBy('project_id');

        $escalatedByProject = ProjectIssue::whereIn('project_id', $projectIds)
            ->whereNotNull('escalated_at')->whereNull('resolved_at')
            ->selectRaw('project_id, COUNT(*) as n')->groupBy('project_id')
            ->pluck('n', 'project_id');

        // ── Milestone roll-ups (billing signal + cost health) ─────────────
        $milestones = ProjectContractMilestone::whereIn('project_id', $projectIds)
            ->get(['project_id', 'currency', 'value', 'status', 'due_on', 'paid_at'])
            ->groupBy('project_id');

        // ── Budget vs expenditure roll-ups (real money) ───────────────────
        // Planned budget lines and actual expenses, summed per project+currency.
        $budgetByProject = ProjectBudgetLine::whereIn('project_id', $projectIds)
            ->selectRaw('project_id, UPPER(currency) as currency, SUM(amount) as total')
            ->groupBy('project_id', DB::raw('UPPER(currency)'))->get()->groupBy('project_id');
        $spentByProject = ProjectExpense::whereIn('project_id', $projectIds)
            ->selectRaw('project_id, UPPER(currency) as currency, SUM(amount) as total')
            ->groupBy('project_id', DB::raw('UPPER(currency)'))->get()->groupBy('project_id');

        // ── Per-project assembly ──────────────────────────────────────────
        $healthMatrix = [];
        $completion   = [];
        $financials   = [];
        $globalTasks  = ['pending' => 0, 'ongoing' => 0, 'completed' => 0, 'overdue' => 0];
        $globalIssues = ['pending' => 0, 'ongoing' => 0, 'resolved' => 0];
        $currencyTotals = []; // currency => [budget, spent]

        // The manager dashboard is about the live portfolio — the health
        // matrix, completion and financial panels cover the projects in scope
        // (a chosen status, or the whole active set), while the global roll-ups
        // (KPI cards, distributions) count every project's tasks and issues as
        // the old PLM did.
        foreach ($projects as $p) {
            $counts = $this->bucketTasks($taskByProjectStatus->get($p->id));
            $overdue = (int) ($overdueByProject[$p->id] ?? 0);
            $total = $counts['pending'] + $counts['ongoing'] + $counts['completed'];
            $progress = $total > 0 ? (int) round($counts['completed'] / $total * 100) : null;

            $issues = $this->bucketIssues($issueByProjectStatus->get($p->id));
            $escalations = (int) ($escalatedByProject[$p->id] ?? 0);
            $mset = $milestones->get($p->id) ?? collect();

            $globalTasks['pending']   += $counts['pending'];
            $globalTasks['ongoing']   += $counts['ongoing'];
            $globalTasks['completed'] += $counts['completed'];
            $globalTasks['overdue']   += $overdue;
            $globalIssues['pending']  += $issues['pending'];
            $globalIssues['ongoing']  += $issues['ongoing'];
            $globalIssues['resolved'] += $issues['resolved'];

            if (! in_array($p->status, $activeStatuses, true)) {
                continue; // counted globally, but not shown per-project.
            }

            $money = $this->money($budgetByProject->get($p->id), $spentByProject->get($p->id));
            foreach ($money as $cur => $row) {
                $currencyTotals[$cur] ??= ['budget' => 0.0, 'spent' => 0.0];
                $currencyTotals[$cur]['budget'] += $row['budget'];
                $currencyTotals[$cur]['spent']  += $row['spent'];
            }

            $healthMatrix[] = [
                'id'          => $p->id,
                'reference'   => $p->reference,
                'name'        => $p->name,
                'status'      => $p->status,
                'lead'        => $p->projectLead ? trim($p->projectLead->first_name . ' ' . $p->projectLead->last_name) : null,
                'time'        => $this->timeHealth($p, $overdue, $counts['pending'] + $counts['ongoing'], $today),
                'cost'        => $this->costHealth($mset, $today),
                'issues'      => $issues['pending'] + $issues['ongoing'] + $issues['resolved'],
                'escalations' => $escalations,
                'tasks'       => $total,
                'progress'    => $progress,
            ];

            $completion[] = [
                'id' => $p->id, 'name' => $p->name, 'progress' => $progress ?? 0,
                'completed' => $this->pct($counts['completed'], $counts['all']),
                'pending'   => $this->pct($counts['pending'], $counts['all']),
                'ongoing'   => $this->pct($counts['ongoing'], $counts['all']),
                'overdue'   => $this->pct($overdue, $counts['all']),
            ];

            if ($money) {
                $financials[] = ['id' => $p->id, 'name' => $p->name, 'by_currency' => $money];
            }
        }

        // ── Logistics roll-up (planning, budgeting, procurement) ──────────
        // Over the projects in scope, so the panel tracks the live portfolio.
        $scopeIds = $projects->whereIn('status', $activeStatuses)->pluck('id');
        // whereHas('task') applies the Task soft-delete scope — a removed task's
        // logistics must not keep counting against the budget.
        $logisticsRows = TaskLogistics::whereIn('project_id', $scopeIds)->whereHas('task')->get();
        $logistics = [
            'tasks_with_requirements' => $logisticsRows->filter(fn ($l) => $l->hasAnyRequirement())->count(),
            'pending_approval'        => $logisticsRows->where('requires_approval', true)->count(),
            'needs'                   => [],
        ];
        foreach (TaskLogistics::NEEDS as $flag) {
            $logistics['needs'][$flag] = $logisticsRows->where($flag, true)->count();
        }

        // ── KPI cards ─────────────────────────────────────────────────────
        $visibleProjects = $projects->whereIn('status', $activeStatuses)->count();

        return [
            'kpis' => [
                'visible_projects' => $visibleProjects,
                'visible_status'   => $status ?? 'active',
                'pending_tasks'    => $globalTasks['pending'],
                'ongoing_tasks'    => $globalTasks['ongoing'],
                'overdue_tasks'    => $globalTasks['overdue'],
            ],
            'health_matrix'       => $healthMatrix,
            'completion'          => $completion,
            'issues_breakdown'    => $globalIssues,
            'task_distribution'   => $globalTasks,
            'financials'          => $financials,
            'financial_totals'    => $currencyTotals,
            'status_counts'       => $projects->countBy('status'),
            'logistics'           => $logistics,
        ];
    }

    private function bucketTasks($rows): array
    {
        $out = ['pending' => 0, 'ongoing' => 0, 'completed' => 0, 'all' => 0];
        foreach ($rows ?? [] as $r) {
            $out['all'] += (int) $r->n;
            if (in_array($r->status, self::PENDING, true)) $out['pending'] += (int) $r->n;
            elseif (in_array($r->status, self::ONGOING, true)) $out['ongoing'] += (int) $r->n;
            elseif ($r->status === Task::DONE) $out['completed'] += (int) $r->n;
        }
        return $out;
    }

    private function bucketIssues($rows): array
    {
        $out = ['pending' => 0, 'ongoing' => 0, 'resolved' => 0];
        foreach ($rows ?? [] as $r) {
            if ($r->status === ProjectIssue::STATUS_OPEN) $out['pending'] += (int) $r->n;
            elseif ($r->status === ProjectIssue::STATUS_IN_PROGRESS) $out['ongoing'] += (int) $r->n;
            else $out['resolved'] += (int) $r->n;
        }
        return $out;
    }

    /**
     * Schedule health, from the signal that actually exists: overdue work.
     * Project-level start/deadline dates are largely unset on imported
     * projects, but their tasks carry deadlines — so a project's timeliness is
     * read from whether its tasks are running late. Red when the project's own
     * deadline has passed or it has overdue tasks; amber when a slice of its
     * open work is overdue; green when work is in flight and on time; grey when
     * there is nothing open to judge.
     */
    private function timeHealth(Project $p, int $overdueTasks, int $openTasks, string $today): string
    {
        if (in_array($p->status, ['delivered', 'cancelled'], true)) return 'done';

        if (($p->deadline && $p->deadline->toDateString() < $today && $p->delivered_on === null)
            || ($openTasks > 0 && $overdueTasks >= max(1, (int) ceil($openTasks * 0.25)))) {
            return 'bad';
        }
        if ($overdueTasks > 0) return 'warn';
        if ($openTasks > 0) return 'ok';

        return 'neutral';
    }

    /** Red if disputed, amber if a due milestone is unpaid past its date, else green. */
    private function costHealth($milestones, string $today): string
    {
        if ($milestones->isEmpty()) return 'neutral';
        if ($milestones->contains('status', 'disputed')) return 'bad';

        $overdueOutstanding = $milestones->first(fn ($m) =>
            in_array($m->status, ['pending', 'invoiced'], true)
            && $m->paid_at === null
            && $m->due_on && $m->due_on->toDateString() < $today);

        return $overdueOutstanding ? 'warn' : 'ok';
    }

    /** Budgeted vs actually spent per currency — real money from the budget
     *  lines and expenses, keyed by currency. Either collection may be null. */
    private function money($budgetRows, $spentRows): array
    {
        $out = [];
        foreach ($budgetRows ?? [] as $row) {
            $cur = $row->currency ?: 'KES';
            $out[$cur] ??= ['budget' => 0.0, 'spent' => 0.0];
            $out[$cur]['budget'] += (float) $row->total;
        }
        foreach ($spentRows ?? [] as $row) {
            $cur = $row->currency ?: 'KES';
            $out[$cur] ??= ['budget' => 0.0, 'spent' => 0.0];
            $out[$cur]['spent'] += (float) $row->total;
        }
        return $out;
    }

    private function pct(int $n, int $total): int
    {
        return $total > 0 ? (int) round($n / $total * 100) : 0;
    }
}
