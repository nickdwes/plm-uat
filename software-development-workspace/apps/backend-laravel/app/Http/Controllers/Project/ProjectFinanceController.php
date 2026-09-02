<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Project\Project;
use App\Models\Project\ProjectBudgetLine;
use App\Models\Project\ProjectExpense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A project's money: planned budget lines, actual expenses, and the
 * budget-vs-expenditure roll-up per currency the dashboard reads.
 */
class ProjectFinanceController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($this->snapshot($project));
    }

    // ── Budget lines ──────────────────────────────────────────────────────

    public function storeBudgetLine(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $this->validateLine($request, creating: true);

        $project->budgetLines()->create($data + [
            'organization_id' => $project->organization_id,
            'created_by'      => $request->user()->id,
        ]);

        return response()->json($this->snapshot($project), 201);
    }

    public function updateBudgetLine(Request $request, Project $project, ProjectBudgetLine $line): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($line->project_id === $project->id, 404);

        $line->update($this->validateLine($request, creating: false));

        return response()->json($this->snapshot($project));
    }

    public function destroyBudgetLine(Project $project, ProjectBudgetLine $line): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($line->project_id === $project->id, 404);

        $line->delete();

        return response()->json($this->snapshot($project));
    }

    /**
     * Build the planned budget from what is already costed: every travel request
     * and every procurement item that carries an estimated cost becomes a budget
     * line. It is idempotent — a line is keyed to the record it came from
     * (source + source_ref), so re-running updates amounts, adds new records and
     * removes lines whose source has gone. Hand-entered ('manual') lines are
     * never touched.
     */
    public function generateBudget(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $planned = collect();

        foreach ($project->travelRequests()->whereNotNull('estimated_cost')->get() as $t) {
            $planned->push([
                'source'     => ProjectBudgetLine::SOURCE_TRAVEL,
                'source_ref' => $t->id,
                'label'      => $t->purpose,
                'category'   => 'travel',
                'currency'   => strtoupper($t->currency ?: 'KES'),
                'amount'     => (float) $t->estimated_cost,
            ]);
        }

        foreach (ProcurementRequest::where('project_id', $project->id)->whereNotNull('estimated_cost')->get() as $p) {
            $qty = max(1, (int) $p->quantity);
            $planned->push([
                'source'     => ProjectBudgetLine::SOURCE_PROCUREMENT,
                'source_ref' => $p->id,
                'label'      => $p->item . ($qty > 1 ? " (×{$qty})" : ''),
                'category'   => 'procurement',
                'currency'   => strtoupper($p->currency ?: 'KES'),
                'amount'     => (float) $p->estimated_cost * $qty,
            ]);
        }

        $created = 0;
        $updated = 0;
        $keptRefs = ['travel' => [], 'procurement' => []];

        foreach ($planned as $row) {
            $keptRefs[$row['source']][] = $row['source_ref'];

            $line = $project->budgetLines()
                ->where('source', $row['source'])->where('source_ref', $row['source_ref'])->first();

            if ($line) {
                $changed = (float) $line->amount !== $row['amount'] || $line->label !== $row['label'];
                $line->update($row);
                $updated += $changed ? 1 : 0;
            } else {
                $project->budgetLines()->create($row + [
                    'organization_id' => $project->organization_id,
                    'created_by'      => $request->user()->id,
                ]);
                $created++;
            }
        }

        // Drop generated lines whose source record no longer carries a cost.
        $removed = 0;
        foreach ([ProjectBudgetLine::SOURCE_TRAVEL, ProjectBudgetLine::SOURCE_PROCUREMENT] as $source) {
            $removed += $project->budgetLines()->where('source', $source)
                ->whereNotIn('source_ref', $keptRefs[$source] ?: [0])->delete();
        }

        return response()->json($this->snapshot($project) + [
            'generated' => ['created' => $created, 'updated' => $updated, 'removed' => $removed],
        ]);
    }

    // ── Expenses ──────────────────────────────────────────────────────────

    public function storeExpense(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $this->validateExpense($request, $project, creating: true);

        $project->expenses()->create($data + [
            'organization_id' => $project->organization_id,
            'created_by'      => $request->user()->id,
        ]);

        return response()->json($this->snapshot($project), 201);
    }

    public function updateExpense(Request $request, Project $project, ProjectExpense $expense): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($expense->project_id === $project->id, 404);

        $expense->update($this->validateExpense($request, $project, creating: false));

        return response()->json($this->snapshot($project));
    }

    public function destroyExpense(Project $project, ProjectExpense $expense): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($expense->project_id === $project->id, 404);

        $expense->delete();

        return response()->json($this->snapshot($project));
    }

    // ── Shape ─────────────────────────────────────────────────────────────

    private function validateLine(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'label'    => [$req, 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:40'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'amount'   => [$req, 'numeric', 'min:0', 'max:9999999999999'],
            'note'     => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function validateExpense(Request $request, Project $project, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'label'    => [$req, 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:40'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'amount'   => [$req, 'numeric', 'min:0', 'max:9999999999999'],
            'spent_on' => ['nullable', 'date'],
            'note'     => ['nullable', 'string', 'max:500'],
            'task_id'  => ['nullable', 'integer'],
            'travel_request_id' => ['nullable', 'integer'],
        ]);

        // A linked task or travel request must belong to this project — no
        // reaching across projects (or tenants) through the finance form.
        if (! empty($data['task_id'])) {
            abort_unless($project->tasks()->whereKey($data['task_id'])->exists(), 422, 'That task is not on this project.');
        }
        if (! empty($data['travel_request_id'])) {
            abort_unless($project->travelRequests()->whereKey($data['travel_request_id'])->exists(), 422, 'That travel request is not on this project.');
        }

        return $data;
    }

    /**
     * Everything the Budget tab and dashboard need in one shot: the raw lines,
     * the raw expenses, and a per-currency budget-vs-actual summary.
     */
    private function snapshot(Project $project): array
    {
        $lines    = $project->budgetLines()->with('creator:id,name')->get();
        $expenses = $project->expenses()
            ->with('creator:id,name', 'task:id,title', 'travelRequest:id,purpose')
            ->get();

        $currencies = $lines->pluck('currency')
            ->merge($expenses->pluck('currency'))
            ->map(fn ($c) => strtoupper($c))
            ->unique()->sort()->values();

        // Money already promised to approved purchases — not yet spent, but no
        // longer available. Without it "remaining" flatters every project that
        // has ordered something it has not been invoiced for.
        $budgetService = app(\App\Services\Procurement\ProcurementBudgetService::class);

        $summary = $currencies->map(function (string $ccy) use ($lines, $expenses, $project, $budgetService) {
            $budgeted = (float) $lines->where('currency', $ccy)->sum('amount');
            $spent    = (float) $expenses->where('currency', $ccy)->sum('amount');
            $committed = $budgetService->committed($project, $ccy);

            return [
                'currency'  => $ccy,
                'budgeted'  => round($budgeted, 2),
                'spent'     => round($spent, 2),
                'committed' => round($committed, 2),
                'remaining' => round($budgeted - $spent, 2),
                'available' => round($budgeted - $spent - $committed, 2),
                'utilization' => $budgeted > 0 ? (int) round($spent / $budgeted * 100) : null,
                'over_budget' => $spent > $budgeted && $budgeted > 0,
            ];
        });

        return [
            'summary'      => $summary,
            'budget_lines' => $lines->map(fn (ProjectBudgetLine $l) => [
                'id'       => $l->id,
                'label'    => $l->label,
                'category' => $l->category,
                'source'   => $l->source ?? ProjectBudgetLine::SOURCE_MANUAL,
                'currency' => strtoupper($l->currency),
                'amount'   => (float) $l->amount,
                'note'     => $l->note,
                'created_by' => $l->relationLoaded('creator') ? $l->creator?->name : null,
            ])->values(),
            'expenses'     => $expenses->map(fn (ProjectExpense $e) => [
                'id'       => $e->id,
                'label'    => $e->label,
                'category' => $e->category,
                'currency' => strtoupper($e->currency),
                'amount'   => (float) $e->amount,
                'spent_on' => $e->spent_on?->toDateString(),
                'note'     => $e->note,
                'task_id'  => $e->task_id,
                'task'     => $e->task?->title,
                'travel_request_id' => $e->travel_request_id,
                'travel'   => $e->travelRequest?->purpose,
                'created_by' => $e->relationLoaded('creator') ? $e->creator?->name : null,
            ])->values(),
            'categories' => ProjectExpense::CATEGORIES,
        ];
    }

    // ── Analytics ─────────────────────────────────────────────────────────

    /**
     * Expense tracking: variance by category, the monthly burn, and a
     * straight-line forecast of where the spend lands by the end of the
     * execution window. Everything is reported in the project's dominant
     * currency — the one carrying the most spend — so a stray foreign-currency
     * line doesn't split the picture; other currencies still show in the
     * per-currency summary the snapshot carries.
     */
    public function analytics(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $lines    = $project->budgetLines()->get();
        $expenses = $project->expenses()->get();

        // Dominant currency: most spend, then most budget, else KES.
        $ccy = $expenses->groupBy(fn ($e) => strtoupper($e->currency))
            ->map(fn ($g) => (float) $g->sum('amount'))->sortDesc()->keys()->first()
            ?? $lines->groupBy(fn ($l) => strtoupper($l->currency))
                ->map(fn ($g) => (float) $g->sum('amount'))->sortDesc()->keys()->first()
            ?? 'KES';

        $lines    = $lines->filter(fn ($l) => strtoupper($l->currency) === $ccy);
        $expenses = $expenses->filter(fn ($e) => strtoupper($e->currency) === $ccy);

        $budgeted = round((float) $lines->sum('amount'), 2);
        $spent    = round((float) $expenses->sum('amount'), 2);

        // Variance by category — every category that appears on either side.
        $cats = $lines->pluck('category')->merge($expenses->pluck('category'))
            ->map(fn ($c) => $c ?: 'uncategorised')->unique();
        $order = array_merge(ProjectExpense::CATEGORIES, ['uncategorised']);
        $byCategory = $cats->map(function (string $cat) use ($lines, $expenses) {
            $match = fn ($v) => ($v->category ?: 'uncategorised') === $cat;
            $b = round((float) $lines->filter($match)->sum('amount'), 2);
            $s = round((float) $expenses->filter($match)->sum('amount'), 2);

            return [
                'category'  => $cat,
                'budgeted'  => $b,
                'spent'     => $s,
                'variance'  => round($b - $s, 2),
                'over'      => $s > $b && $b > 0,
                'utilization' => $b > 0 ? (int) round($s / $b * 100) : null,
            ];
        })->sortBy(fn ($r) => array_search($r['category'], $order))->values();

        // Monthly burn (only dated expenses) — with a running cumulative.
        $running = 0.0;
        $burn = $expenses->filter(fn ($e) => $e->spent_on)
            ->groupBy(fn ($e) => $e->spent_on->format('Y-m'))
            ->sortKeys()
            ->map(function ($g, $month) use (&$running) {
                $m = round((float) $g->sum('amount'), 2);
                $running += $m;

                return ['month' => $month, 'spent' => $m, 'cumulative' => round($running, 2)];
            })->values();

        $undated = round((float) $expenses->filter(fn ($e) => ! $e->spent_on)->sum('amount'), 2);

        return response()->json([
            'currency'    => $ccy,
            'budgeted'    => $budgeted,
            'spent'       => $spent,
            'remaining'   => round($budgeted - $spent, 2),
            'variance'    => round($budgeted - $spent, 2),
            'utilization' => $budgeted > 0 ? (int) round($spent / $budgeted * 100) : null,
            'over_budget' => $spent > $budgeted && $budgeted > 0,
            'by_category' => $byCategory,
            'burn'        => $burn,
            'undated_spend' => $undated,
            'forecast'    => $this->forecast($project, $budgeted, $spent),
            'linkage'     => [
                'to_tasks'  => $expenses->whereNotNull('task_id')->count(),
                'to_travel' => $expenses->whereNotNull('travel_request_id')->count(),
                'unlinked'  => $expenses->whereNull('task_id')->whereNull('travel_request_id')->count(),
            ],
        ]);
    }

    /**
     * A straight-line forecast: at the current burn per elapsed day, where does
     * spend land at the end of the execution window? Null when there is no
     * window to project across or the project hasn't started spending time yet.
     */
    private function forecast(Project $project, float $budgeted, float $spent): ?array
    {
        $start = $project->execution_start ?? $project->started_on;
        $end   = $project->execution_end ?? $project->contract_completion_date ?? $project->deadline;

        if (! $start || ! $end || $end->lessThanOrEqualTo($start)) {
            return null;
        }

        $today    = now()->startOfDay();
        $total    = $start->diffInDays($end);
        $elapsed  = max(0, min($total, $start->diffInDays($today->greaterThan($start) ? $today : $start)));
        $elapsedPct = $total > 0 ? (int) round($elapsed / $total * 100) : 0;

        $perDay    = $elapsed > 0 ? $spent / $elapsed : 0.0;
        $projected = $elapsed > 0 ? round($perDay * $total, 2) : $spent;

        return [
            'window_start' => $start->toDateString(),
            'window_end'   => $end->toDateString(),
            'total_days'   => $total,
            'elapsed_days' => $elapsed,
            'elapsed_pct'  => $elapsedPct,
            'burn_per_day' => round($perDay, 2),
            'projected_total' => $projected,
            'projected_variance' => round($budgeted - $projected, 2),
            'projected_over' => $budgeted > 0 && $projected > $budgeted,
        ];
    }
}
