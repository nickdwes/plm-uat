<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Project\Project;
use App\Models\Project\ProjectPerson;
use App\Models\Task\TaskPrerequisite;
use Illuminate\Http\JsonResponse;

/**
 * Project controls & alerts — one feed that watches the logistics data for the
 * conditions a PM should act on: travel about to start, approvals still waiting,
 * long-lead items that will miss their date, spend over budget, people
 * over-allocated, and procurement that was flagged but never raised.
 *
 * It computes on read from the registers already in place — nothing is stored;
 * an alert is simply the current answer to "what needs attention right now".
 */
class ProjectAlertController extends Controller
{
    private const CRITICAL = 'critical';
    private const WARNING  = 'warning';
    private const INFO     = 'info';

    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $alerts = collect()
            ->concat($this->travelAlerts($project))
            ->concat($this->approvalAlerts($project))
            ->concat($this->procurementAlerts($project))
            ->concat($this->budgetAlerts($project))
            ->concat($this->resourceAlerts($project))
            ->concat($this->prerequisiteAlerts($project));

        $rank = [self::CRITICAL => 0, self::WARNING => 1, self::INFO => 2];
        $alerts = $alerts->sortBy(fn ($a) => $rank[$a['severity']])->values();

        return response()->json([
            'data'    => $alerts,
            'summary' => [
                'total'    => $alerts->count(),
                'critical' => $alerts->where('severity', self::CRITICAL)->count(),
                'warning'  => $alerts->where('severity', self::WARNING)->count(),
                'info'     => $alerts->where('severity', self::INFO)->count(),
            ],
        ]);
    }

    private function alert(string $severity, string $category, string $title, string $detail, string $tab, int $count = 1): array
    {
        return compact('severity', 'category', 'title', 'detail', 'tab', 'count');
    }

    // ── Travel ────────────────────────────────────────────────────────────────

    private function travelAlerts(Project $project): array
    {
        $pending = $project->travelRequests()->where('approval_status', 'pending')->get();
        $out = [];

        $overdue = $pending->filter(fn ($r) => $r->start_date && $r->start_date->isPast());
        if ($overdue->isNotEmpty()) {
            $out[] = $this->alert(self::CRITICAL, 'travel', 'Travel unapproved past its start date',
                $overdue->count() . ' trip' . ($overdue->count() === 1 ? '' : 's') . ' start on or before today but are not yet approved.',
                'logistics', $overdue->count());
        }

        $soon = $pending->filter(fn ($r) => $r->start_date && ! $r->start_date->isPast() && $r->start_date->lessThanOrEqualTo(now()->addDays(7)));
        if ($soon->isNotEmpty()) {
            $out[] = $this->alert(self::WARNING, 'travel', 'Travel starts within 7 days',
                $soon->count() . ' trip' . ($soon->count() === 1 ? '' : 's') . ' start this week and still await approval.',
                'logistics', $soon->count());
        }

        return $out;
    }

    // ── Approvals still pending ────────────────────────────────────────────────

    private function approvalAlerts(Project $project): array
    {
        $out = [];

        $travelPending = $project->travelRequests()->where('approval_status', 'pending')->count();
        if ($travelPending) {
            $out[] = $this->alert(self::WARNING, 'approval', 'Travel approvals pending',
                "{$travelPending} travel request" . ($travelPending === 1 ? '' : 's') . ' waiting on a decision.',
                'logistics', $travelPending);
        }

        $procPending = ProcurementRequest::where('project_id', $project->id)->where('approval_status', 'pending')->count();
        if ($procPending) {
            $out[] = $this->alert(self::WARNING, 'approval', 'Procurement approvals pending',
                "{$procPending} procurement item" . ($procPending === 1 ? '' : 's') . ' waiting on a decision.',
                'logistics', $procPending);
        }

        return $out;
    }

    // ── Procurement long-lead ──────────────────────────────────────────────────

    private function procurementAlerts(Project $project): array
    {
        $items = ProcurementRequest::where('project_id', $project->id)
            ->whereNotIn('status', ['received', 'cancelled'])
            ->get();
        $out = [];

        // Won't arrive in time: the days left until required_by are fewer than the
        // lead time. This is a schedule risk regardless of approval state.
        $threatening = $items->filter(function ($r) {
            return $r->lead_time_days && $r->required_by
                && now()->startOfDay()->diffInDays($r->required_by, false) < $r->lead_time_days;
        });
        if ($threatening->isNotEmpty()) {
            $out[] = $this->alert(self::CRITICAL, 'procurement', 'Long-lead items threaten the timeline',
                $threatening->count() . ' item' . ($threatening->count() === 1 ? '' : 's') . ' cannot arrive by their required date at the stated lead time.',
                'logistics', $threatening->count());
        }

        // Flagged long-lead but still comfortable — worth watching, not urgent.
        $watch = $items->filter(fn ($r) => $r->isLongLead())->reject(fn ($r) => $threatening->contains('id', $r->id));
        if ($watch->isNotEmpty()) {
            $out[] = $this->alert(self::INFO, 'procurement', 'Long-lead items in progress',
                $watch->count() . ' long-lead item' . ($watch->count() === 1 ? '' : 's') . ' to keep an eye on.',
                'logistics', $watch->count());
        }

        return $out;
    }

    // ── Budget ─────────────────────────────────────────────────────────────────

    private function budgetAlerts(Project $project): array
    {
        $lines    = $project->budgetLines()->get();
        $expenses = $project->expenses()->get();
        $out = [];

        $currencies = $lines->pluck('currency')->merge($expenses->pluck('currency'))
            ->map(fn ($c) => strtoupper($c))->unique();

        foreach ($currencies as $ccy) {
            $budgeted = (float) $lines->filter(fn ($l) => strtoupper($l->currency) === $ccy)->sum('amount');
            $spent    = (float) $expenses->filter(fn ($e) => strtoupper($e->currency) === $ccy)->sum('amount');

            if ($budgeted > 0 && $spent > $budgeted) {
                $over = $spent - $budgeted;
                $out[] = $this->alert(self::CRITICAL, 'budget', "Spend over budget ({$ccy})",
                    "Actual spend exceeds the budget by " . number_format($over, 2) . " {$ccy}.",
                    'budget');
            }
        }

        // Straight-line forecast overrun, when there is an execution window.
        $forecast = $this->forecastOverrun($project, $lines, $expenses);
        if ($forecast) {
            $out[] = $forecast;
        }

        return $out;
    }

    private function forecastOverrun(Project $project, $lines, $expenses): ?array
    {
        $start = $project->execution_start ?? $project->started_on;
        $end   = $project->execution_end ?? $project->contract_completion_date ?? $project->deadline;
        if (! $start || ! $end || $end->lessThanOrEqualTo($start)) {
            return null;
        }

        // Dominant currency only — the forecast is a single-number heads-up.
        $ccy = $expenses->groupBy(fn ($e) => strtoupper($e->currency))
            ->map(fn ($g) => (float) $g->sum('amount'))->sortDesc()->keys()->first() ?? 'KES';
        $budgeted = (float) $lines->filter(fn ($l) => strtoupper($l->currency) === $ccy)->sum('amount');
        $spent    = (float) $expenses->filter(fn ($e) => strtoupper($e->currency) === $ccy)->sum('amount');
        if ($budgeted <= 0 || $spent <= 0) {
            return null;
        }

        $total   = $start->diffInDays($end);
        $elapsed = max(1, min($total, $start->diffInDays(now()->greaterThan($start) ? now() : $start)));
        $projected = $spent / $elapsed * $total;

        if ($projected > $budgeted && $spent <= $budgeted) {   // not yet over, but heading there
            return $this->alert(self::WARNING, 'budget', "Forecast to exceed budget ({$ccy})",
                'At the current burn rate the project is projected to spend ' . number_format($projected, 0) . " {$ccy} against a budget of " . number_format($budgeted, 0) . '.',
                'budget');
        }

        return null;
    }

    // ── Resource over-allocation ───────────────────────────────────────────────

    private function resourceAlerts(Project $project): array
    {
        $over = ProjectPerson::where('project_id', $project->id)
            ->where('role_kind', ProjectPerson::KIND_MEMBER)
            ->whereNotNull('capacity_percent')
            ->with('employee:id,first_name,last_name')
            ->get()
            ->groupBy('employee_id')
            ->filter(fn ($g) => (int) $g->sum('capacity_percent') > 100);

        if ($over->isEmpty()) {
            return [];
        }

        $names = $over->map(fn ($g) => trim(($g->first()->employee?->first_name ?? '') . ' ' . ($g->first()->employee?->last_name ?? '')))
            ->filter()->take(3)->implode(', ');

        return [$this->alert(self::WARNING, 'resource', 'People over-allocated',
            $over->count() . ' team member' . ($over->count() === 1 ? '' : 's') . " booked beyond 100% capacity" . ($names ? " ({$names})" : '') . '.',
            'logistics', $over->count())];
    }

    // ── Procurement flagged but never raised ───────────────────────────────────

    private function prerequisiteAlerts(Project $project): array
    {
        $needing = TaskPrerequisite::where('project_id', $project->id)
            ->where('procurement_needed', true)
            ->where('status', '!=', TaskPrerequisite::STATUS_DONE)
            ->get();
        if ($needing->isEmpty()) {
            return [];
        }

        $raisedTaskIds = ProcurementRequest::where('project_id', $project->id)
            ->whereNotNull('task_id')->pluck('task_id')->unique();

        $gap = $needing->reject(fn ($p) => $raisedTaskIds->contains($p->task_id));
        if ($gap->isEmpty()) {
            return [];
        }

        $one = $gap->count() === 1;

        return [$this->alert(self::WARNING, 'procurement', 'Procurement flagged but not raised',
            $gap->count() . ' task requirement' . ($one ? '' : 's') . ' (asset, software or procurement) still ' . ($one ? 'has' : 'have') . ' no procurement request against ' . ($one ? 'it' : 'them') . '.',
            'tasks', $gap->count())];
    }
}
