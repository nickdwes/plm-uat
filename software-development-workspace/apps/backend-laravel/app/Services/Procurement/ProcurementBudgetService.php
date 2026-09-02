<?php

namespace App\Services\Procurement;

use App\Models\Procurement\ProcurementRequest;
use App\Models\Procurement\ProcurementSetting;
use App\Models\Project\Project;

/**
 * The finance gate: may this purchase be committed against the project budget?
 *
 * The number that matters is not "budget minus what has been spent" — it is
 * "budget minus spend minus what is already promised". An approved request
 * nobody has invoiced yet is money gone; leaving it out of the arithmetic is
 * how a project is over budget the moment the invoices land. That promised
 * money is the commitment, computed here and nowhere stored: a cached total is
 * a consistency liability with no payoff at this size.
 */
class ProcurementBudgetService
{
    /**
     * What the budget looks like for a project in one currency.
     *
     * @return array{budgeted:float, spent:float, committed:float, available:float}
     */
    public function position(Project $project, string $currency): array
    {
        $currency = strtoupper($currency);

        $budgeted = (float) $project->budgetLines()
            ->whereRaw('UPPER(currency) = ?', [$currency])->sum('amount');
        $spent = (float) $project->expenses()
            ->whereRaw('UPPER(currency) = ?', [$currency])->sum('amount');
        $committed = $this->committed($project, $currency);

        return [
            'budgeted'  => round($budgeted, 2),
            'spent'     => round($spent, 2),
            'committed' => round($committed, 2),
            'available' => round($budgeted - $spent - $committed, 2),
        ];
    }

    /**
     * Money promised but not yet spent: approved requests that have not become
     * actual expenditure.
     *
     * Requests drop out of the commitment as their spend appears elsewhere —
     * once an invoice is paid it is posted as a project expense, and counting
     * it in both places would charge the budget twice.
     */
    public function committed(Project $project, string $currency, ?int $excludeRequestId = null): float
    {
        $currency = strtoupper($currency);

        // Commitment ends when the money is spent, not when the goods arrive:
        // a delivered-but-unpaid purchase is still owed, and dropping it here
        // would understate the exposure for exactly as long as the invoice
        // takes to settle. `paid` and `closed` are the states where the spend
        // has been posted as a project expense instead.
        return (float) ProcurementRequest::where('project_id', $project->id)
            ->whereRaw('UPPER(currency) = ?', [$currency])
            ->where('approval_status', 'approved')
            ->where('status', '!=', 'cancelled')
            ->whereNotIn('lifecycle_stage', ['paid', 'closed', 'cancelled', 'rejected'])
            ->when($excludeRequestId, fn ($q) => $q->whereKeyNot($excludeRequestId))
            ->get()
            ->sum(fn (ProcurementRequest $r) => $r->committedAmount());
    }

    /**
     * Check a request against its project's budget and record the verdict on
     * it. The snapshot is kept because an audit asks what the numbers were when
     * the decision was made, not what they are now.
     *
     * @return array{result:string, ok:bool, amount:float, available:?float, committed:?float, budgeted:?float, spent:?float, message:?string}
     */
    public function check(ProcurementRequest $request): array
    {
        $amount = $request->committedAmount();
        $project = $request->project;

        // A purchase with no project has no budget to check it against.
        // Department budgets do not exist yet; this passes, and says so.
        if (! $project) {
            $verdict = [
                'result' => ProcurementRequest::BUDGET_NONE, 'ok' => true, 'amount' => $amount,
                'available' => null, 'committed' => null, 'budgeted' => null, 'spent' => null,
                'message' => 'No project budget applies to this request.',
            ];

            return $this->stamp($request, $verdict);
        }

        $position = $this->position($project, $request->currency ?: 'KES');
        // The request's own commitment must not count against itself.
        $committed = $this->committed($project, $request->currency ?: 'KES', $request->id);
        $available = round($position['budgeted'] - $position['spent'] - $committed, 2);

        $exceeded = $position['budgeted'] > 0 && $amount > $available;

        return $this->stamp($request, [
            'result'    => $exceeded ? ProcurementRequest::BUDGET_EXCEEDED : ProcurementRequest::BUDGET_OK,
            'ok'        => ! $exceeded,
            'amount'    => $amount,
            'available' => $available,
            'committed' => $committed,
            'budgeted'  => $position['budgeted'],
            'spent'     => $position['spent'],
            'message'   => $exceeded
                ? sprintf(
                    'Budget exceeded: %s %s requested against %s %s available (budget %s, spent %s, committed %s).',
                    strtoupper($request->currency), number_format($amount, 2),
                    strtoupper($request->currency), number_format($available, 2),
                    number_format($position['budgeted'], 2), number_format($position['spent'], 2), number_format($committed, 2),
                )
                : null,
        ]);
    }

    /**
     * Whether this purchase erodes the project's margin past the floor the
     * organisation set — the trigger for an executive stage the amount alone
     * would not have called for.
     */
    public function breachesMarginFloor(ProcurementRequest $request): bool
    {
        $project = $request->project;
        $floor = ProcurementSetting::forOrganization($request->organization_id)->margin_floor_percent;

        if (! $project || $floor === null || ! $project->contract_value) {
            return false;
        }

        $currency = $request->currency ?: 'KES';
        $position = $this->position($project, $currency);
        $costBase = $position['spent'] + $position['committed'] + $request->committedAmount();
        $ceiling = (float) $project->contract_value * (1 - ((float) $floor / 100));

        return $costBase > $ceiling;
    }

    private function stamp(ProcurementRequest $request, array $verdict): array
    {
        $request->forceFill([
            'budget_checked_at'       => now(),
            'budget_available_amount' => $verdict['available'],
            'budget_committed_amount' => $verdict['committed'],
            'budget_result'           => $verdict['result'],
        ])->save();

        return $verdict;
    }
}
