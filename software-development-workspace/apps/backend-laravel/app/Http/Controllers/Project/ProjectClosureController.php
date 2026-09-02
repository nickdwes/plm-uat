<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectBom;
use App\Models\Project\ProjectContractMilestone;
use App\Models\Project\ProjectOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Financial closure — the end of the order lifecycle. It reads the checklist
 * the spec lists (orders accepted, billing settled, not over budget) and only
 * lets a project be closed when every gate is green, so closure means the same
 * thing every time rather than being a button someone pressed early.
 */
class ProjectClosureController extends Controller
{
    public function readiness(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($this->checklist($project));
    }

    public function close(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $check = $this->checklist($project);
        abort_unless($check['ready'], 422, 'The project is not ready to close — clear the outstanding items first.');

        $project->update([
            'financially_closed_at' => now(),
            'financially_closed_by' => $request->user()->id,
        ]);

        return response()->json($this->checklist($project->fresh()));
    }

    public function reopen(Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $project->update(['financially_closed_at' => null, 'financially_closed_by' => null]);

        return response()->json($this->checklist($project->fresh()));
    }

    /**
     * The closure checklist and whether every gate is met. Each gate carries a
     * count so the UI can say exactly what is still outstanding.
     */
    private function checklist(Project $project): array
    {
        $bomApproved = $project->boms()->where('status', ProjectBom::STATUS_APPROVED)->exists();

        $orders = $project->orders()->get();
        $ordersOpen = $orders->whereNotIn('status', [
            ProjectOrder::STATUS_ACCEPTED, ProjectOrder::STATUS_CLOSED, ProjectOrder::STATUS_CANCELLED,
        ])->count();

        $billing = ProjectContractMilestone::where('project_id', $project->id)->get();
        $billingOutstanding = $billing->whereNotIn('status', ['paid', 'waived'])->count();

        $overBudget = $this->overBudget($project);

        $gates = [
            'orders_settled'  => ['met' => $ordersOpen === 0, 'open' => $ordersOpen, 'total' => $orders->count()],
            'billing_settled' => ['met' => $billingOutstanding === 0, 'open' => $billingOutstanding, 'total' => $billing->count()],
            'within_budget'   => ['met' => ! $overBudget],
        ];
        $ready = collect($gates)->every(fn ($g) => $g['met']);

        return [
            'ready'        => $ready && ! $project->financially_closed_at,
            'closed'       => $project->financially_closed_at !== null,
            'closed_at'    => $project->financially_closed_at?->toDateTimeString(),
            'closed_by'    => $project->financiallyClosedBy?->name,
            'bom_approved' => $bomApproved,
            'gates'        => $gates,
        ];
    }

    /** Any currency where actual spend has passed the budget. */
    private function overBudget(Project $project): bool
    {
        $lines    = $project->budgetLines()->get();
        $expenses = $project->expenses()->get();

        foreach ($lines->pluck('currency')->merge($expenses->pluck('currency'))->map(fn ($c) => strtoupper($c))->unique() as $ccy) {
            $budgeted = (float) $lines->filter(fn ($l) => strtoupper($l->currency) === $ccy)->sum('amount');
            $spent    = (float) $expenses->filter(fn ($e) => strtoupper($e->currency) === $ccy)->sum('amount');
            if ($budgeted > 0 && $spent > $budgeted) {
                return true;
            }
        }

        return false;
    }
}
