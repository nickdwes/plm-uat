<?php

namespace App\Services\Travel;

use App\Models\Travel\TravelAllowanceRate;
use App\Models\Travel\TravelRequest;
use Illuminate\Support\Facades\DB;

/**
 * Checks a traveller's estimates against the organisation's allowance policy.
 *
 * The rate card is a ceiling, not a calculator. A traveller enters what the
 * trip will actually cost — a real flight has a real price — and each line is
 * measured against the rate that applies to their grade, destination and the
 * date they travel.
 *
 * Going over is flagged, never blocked. A request that cannot be submitted
 * because the only available fare exceeds the limit does not stop the trip; it
 * stops the record of it, and the approval then happens over email where
 * nobody can audit it. An approver seeing "exceeds policy by 2,000" makes a
 * decision. An approver seeing nothing makes none.
 *
 * `policy_limit` and `exceeds_policy` are written onto each line rather than
 * derived on read, for the same reason the rate used to be: revising the rate
 * card afterwards must not silently re-judge a decision an approver already
 * made against the old one.
 */
class TravelCostingService
{
    /**
     * Re-evaluate every line of a request against the policy in force.
     *
     * @return TravelRequest the request with totals and flags refreshed
     */
    public function evaluate(TravelRequest $request, ?int $jobGradeId): TravelRequest
    {
        return DB::transaction(function () use ($request, $jobGradeId) {
            $onDate = $request->departure_date?->toDateString() ?? now()->toDateString();
            $total  = 0.0;

            foreach ($request->items as $item) {
                $rate = $this->rateFor(
                    organizationId: $request->organization_id,
                    categoryId: $item->travel_expense_category_id,
                    jobGradeId: $jobGradeId,
                    destinationId: $request->travel_destination_id,
                    onDate: $onDate,
                );

                // The ceiling for a per-day allowance is the daily rate across
                // the whole trip; a per-trip one is flat however long it runs.
                $limit = $rate
                    ? (float) $rate->amount * ($rate->unit === 'per_trip' ? 1 : max(1, (int) $request->days))
                    : null;

                $item->update([
                    'rate_applied'   => $rate?->amount,
                    'policy_limit'   => $limit,
                    // An unpriced category is not an over-spend. Flagging it
                    // would tell an approver the traveller broke a rule that
                    // was never set.
                    'exceeds_policy' => $limit !== null && (float) $item->amount_claimed > $limit,
                ]);

                $total += (float) $item->amount_claimed;
            }

            $request->update(['total_claimed' => $total]);

            return $request->fresh('items');
        });
    }

    /**
     * The allowance in force for one category, or null when none is priced.
     *
     * Candidates are the specific match plus the "applies to all" rows, ordered
     * by specificity rather than filtered — so a missing grade-specific rate
     * falls back to the default instead of finding nothing.
     */
    public function rateFor(
        int $organizationId,
        int $categoryId,
        ?int $jobGradeId,
        ?int $destinationId,
        string $onDate,
    ): ?TravelAllowanceRate {
        return TravelAllowanceRate::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('travel_expense_category_id', $categoryId)
            ->where(fn ($q) => $q->whereNull('job_grade_id')->orWhere('job_grade_id', $jobGradeId))
            ->where(fn ($q) => $q->whereNull('travel_destination_id')->orWhere('travel_destination_id', $destinationId))
            ->effectiveOn($onDate)
            ->mostSpecificFirst()
            ->first();
    }

    /**
     * The lines that went over, for the compliance summary an approver reads
     * before deciding.
     *
     * @return array<int, array{category: string, over_by: float}>
     */
    public function breaches(TravelRequest $request): array
    {
        return $request->items
            ->where('exceeds_policy', true)
            ->map(fn ($item) => [
                'category' => $item->category?->name ?? 'Uncategorised',
                'over_by'  => round((float) $item->amount_claimed - (float) $item->policy_limit, 2),
            ])
            ->values()
            ->all();
    }
}
