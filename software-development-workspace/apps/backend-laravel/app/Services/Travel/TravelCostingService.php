<?php

namespace App\Services\Travel;

use App\Models\Travel\TravelAllowanceRate;
use App\Models\Travel\TravelExpenseCategory;
use App\Models\Travel\TravelRequest;
use Illuminate\Support\Facades\DB;

/**
 * Costs a travel request against the organisation's allowance rate card.
 *
 * The rate that applies depends on the traveller's grade, where they are
 * going, what the money is for, and when they are going. Resolution takes the
 * most specific row in force on the departure date, and a null grade or
 * destination on a rate means "applies to all" rather than "unset" — which is
 * what lets an organisation state one meals rate globally and override it for
 * two expensive cities.
 */
class TravelCostingService
{
    /**
     * Rebuild the costed lines for a request.
     *
     * The resolved rate is written onto each line and never recalculated
     * afterwards. Without that, correcting the rate card silently rewrites the
     * value of every historical request that used it — a change that is
     * invisible until someone audits a payment.
     */
    public function cost(TravelRequest $request, ?int $jobGradeId): TravelRequest
    {
        return DB::transaction(function () use ($request, $jobGradeId) {
            $categories = TravelExpenseCategory::where('organization_id', $request->organization_id)
                ->where('is_active', true)
                ->get();

            $request->items()->delete();
            $total = 0.0;

            foreach ($categories as $category) {
                $rate = $this->rateFor(
                    organizationId: $request->organization_id,
                    categoryId: $category->id,
                    jobGradeId: $jobGradeId,
                    destinationId: $request->travel_destination_id,
                    onDate: $request->departure_date?->toDateString() ?? now()->toDateString(),
                );

                // No rate is not zero. A category the organisation has not
                // priced for this grade or destination is left off the request
                // entirely, rather than appearing as a legitimate nil claim.
                if (! $rate) {
                    continue;
                }

                $quantity = $rate->unit === 'per_trip' ? 1 : max(1, (int) $request->days);
                $amount   = (float) $rate->amount * $quantity;

                $request->items()->create([
                    'travel_expense_category_id' => $category->id,
                    'quantity'                   => $quantity,
                    'rate_applied'               => $rate->amount,
                    'unit'                       => $rate->unit,
                    'amount_claimed'             => $amount,
                ]);

                $total += $amount;
            }

            $request->update(['total_claimed' => $total]);

            return $request->fresh('items');
        });
    }

    /**
     * The rate in force for one category, or null when none is priced.
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
            // Candidates are the specific match and the "applies to all" rows;
            // ordering then picks the most specific rather than filtering, so
            // a missing specific rate falls back instead of costing nothing.
            ->where(fn ($q) => $q->whereNull('job_grade_id')->orWhere('job_grade_id', $jobGradeId))
            ->where(fn ($q) => $q->whereNull('travel_destination_id')->orWhere('travel_destination_id', $destinationId))
            ->effectiveOn($onDate)
            ->mostSpecificFirst()
            ->first();
    }
}
