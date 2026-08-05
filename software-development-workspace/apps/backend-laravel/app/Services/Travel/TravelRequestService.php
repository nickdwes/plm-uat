<?php

namespace App\Services\Travel;

use App\Models\Travel\TravelApproval;
use App\Models\Travel\TravelApprovalStage;
use App\Models\Travel\TravelRequest;
use App\Models\Travel\TravelRequestItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TravelRequestService
{
    public function __construct(
        private readonly TravelPlacementResolver $placement,
        private readonly TravelCostingService $costing,
    ) {}

    /**
     * Raise a request in one organisation, on behalf of one traveller.
     *
     * The organisation is the one the request is being raised in, not the
     * traveller's primary one. Their department, function and grade are all
     * read from that placement, so a seconded employee's request routes to the
     * managers of the company paying for the trip.
     */
    public function create(array $data, int $organizationId, ?int $actorId): TravelRequest
    {
        $employeeId = (int) $data['employee_id'];

        // Tenancy scoping does not supply this: a user with access to two
        // organisations could otherwise raise a request in one on behalf of
        // their position in the other.
        if (! $this->placement->isMember($employeeId, $organizationId)) {
            throw new RuntimeException('That employee is not a member of this organisation.');
        }

        return DB::transaction(function () use ($data, $organizationId, $employeeId, $actorId) {
            $placement = $this->placement->placement($employeeId, $organizationId);

            $request = TravelRequest::create([
                'organization_id'    => $organizationId,
                'employee_id'        => $employeeId,
                // Denormalised on purpose: this decides the first approver and
                // must not shift under a request already in flight because
                // somebody moved department.
                'department_id'      => $placement['department_id'],
                'functional_unit_id' => $placement['functional_unit_id'],
                'created_by'         => $actorId,
                'days'               => $this->days($data['departure_date'], $data['return_date']),
            ] + collect($data)->only([
                'account_id', 'travel_destination_id', 'travel_mode',
                'departure_date', 'return_date', 'purpose', 'business_impact',
                'currency', 'advance_required',
            ])->all());

            $request->update(['reference' => $this->reference($request)]);
            $request->recordActivity('created', 'Draft created', $employeeId);

            // A new request has no lines yet: the traveller adds them in the
            // expenses step, and each is checked against policy as it lands.
            return $request->fresh();
        });
    }

    public function update(TravelRequest $request, array $data): TravelRequest
    {
        if (! $request->isEditable()) {
            throw new RuntimeException('Only a draft or rejected request can be edited.');
        }

        return DB::transaction(function () use ($request, $data) {
            $request->update(collect($data)->only([
                'account_id', 'travel_destination_id', 'travel_mode',
                'departure_date', 'return_date', 'purpose', 'business_impact', 'advance_required',
            ])->all() + [
                'days' => $this->days(
                    $data['departure_date'] ?? $request->departure_date,
                    $data['return_date'] ?? $request->return_date,
                ),
            ]);

            $placement = $this->placement->placement($request->employee_id, $request->organization_id);

            // Re-checked rather than re-costed: changing the destination or
            // the dates moves the ceiling, so a line that was within policy
            // may no longer be.
            return $this->costing->evaluate($request->fresh('items'), $placement['job_grade_id']);
        });
    }

    /**
     * Add an estimated expense, and check it against policy.
     *
     * The traveller states the amount; the rate card decides whether it is
     * within the allowance. Going over is recorded, not refused — an approver
     * seeing "exceeds policy by 2,000" makes a decision, and one seeing a
     * blocked form makes none, because the trip happens anyway and the
     * approval moves to email.
     */
    public function addItem(TravelRequest $request, array $data): TravelRequest
    {
        if (! $request->isEditable()) {
            throw new RuntimeException('Only a draft or rejected request can be edited.');
        }

        return DB::transaction(function () use ($request, $data) {
            $request->items()->create([
                'travel_expense_category_id' => $data['travel_expense_category_id'],
                'description'                => $data['description'] ?? null,
                'quantity'                   => $data['quantity'] ?? 1,
                'unit'                       => $data['unit'] ?? 'per_trip',
                'amount_claimed'             => $data['amount_claimed'],
            ]);

            return $this->reevaluate($request);
        });
    }

    public function updateItem(TravelRequest $request, TravelRequestItem $item, array $data): TravelRequest
    {
        if (! $request->isEditable()) {
            throw new RuntimeException('Only a draft or rejected request can be edited.');
        }

        return DB::transaction(function () use ($request, $item, $data) {
            $item->update(collect($data)->only([
                'travel_expense_category_id', 'description', 'quantity', 'unit', 'amount_claimed',
            ])->all());

            return $this->reevaluate($request);
        });
    }

    public function removeItem(TravelRequest $request, TravelRequestItem $item): TravelRequest
    {
        if (! $request->isEditable()) {
            throw new RuntimeException('Only a draft or rejected request can be edited.');
        }

        return DB::transaction(function () use ($request, $item) {
            $item->delete();

            return $this->reevaluate($request);
        });
    }

    /** Re-check every line, since the total decides which approvals apply. */
    private function reevaluate(TravelRequest $request): TravelRequest
    {
        $placement = $this->placement->placement($request->employee_id, $request->organization_id);

        return $this->costing->evaluate($request->fresh('items'), $placement['job_grade_id']);
    }

    /**
     * Submit for approval, fixing the chain that will apply.
     *
     * The stages are chosen here from the organisation's configuration and the
     * request total, then written as rows. Editing the policy afterwards
     * cannot add an approver to a request already moving, nor drop one before
     * the money is released.
     */
    public function submit(TravelRequest $request): TravelRequest
    {
        if (! $request->isEditable()) {
            throw new RuntimeException('This request has already been submitted.');
        }

        if ($request->items()->count() === 0) {
            // An empty request in an approver's queue wastes their attention
            // and tells them nothing about what is being asked for.
            throw new RuntimeException('Add at least one estimated expense before submitting.');
        }

        return DB::transaction(function () use ($request) {
            $stages = TravelApprovalStage::where('organization_id', $request->organization_id)
                ->where('is_active', true)
                ->orderBy('sequence')
                ->get()
                ->filter(fn (TravelApprovalStage $s) => $s->appliesTo((float) $request->total_claimed, $request->currency))
                ->values();

            // A configuration that resolves to no approvals would let a
            // request approve itself. Refused here rather than discovered when
            // one is paid.
            if ($stages->isEmpty()) {
                throw new RuntimeException('No approval stages are configured for this organisation.');
            }

            $placement = $this->placement->placement($request->employee_id, $request->organization_id);
            $request->approvals()->delete();

            foreach ($stages as $stage) {
                $request->approvals()->create([
                    'stage'                => $stage->stage,
                    'sequence'             => $stage->sequence,
                    'expected_approver_id' => $this->placement->approverFor($stage->stage, $placement),
                    'status'               => 'pending',
                ]);
            }

            $request->update(['status' => 'in_approval', 'submitted_at' => now()]);
            $request->recordActivity(
                'submitted',
                'Submitted for approval (' . $stages->count() . ' stage' . ($stages->count() > 1 ? 's' : '') . ')',
                $request->employee_id,
            );

            return $request->fresh(['approvals', 'items']);
        });
    }

    /**
     * Record a decision on the stage the request is currently waiting on.
     *
     * Only the earliest pending stage may be acted on. Without that a later
     * approver could sign first and leave the chain looking complete while an
     * earlier stage was never seen.
     */
    public function decide(TravelRequest $request, string $decision, int $employeeId, ?string $comment = null): TravelRequest
    {
        $approval = $request->approvals()->where('status', 'pending')->orderBy('sequence')->first();

        if (! $approval) {
            throw new RuntimeException('This request is not awaiting approval.');
        }

        return DB::transaction(function () use ($request, $approval, $decision, $employeeId, $comment) {
            $approval->update([
                'status'                 => $decision === 'approve' ? 'approved' : 'rejected',
                'decided_by_employee_id' => $employeeId,
                'comment'                => $comment,
                'decided_at'             => now(),
            ]);

            $request->recordActivity(
                $decision === 'approve' ? 'approved' : 'rejected',
                ucfirst(str_replace('_', ' ', $approval->stage)) . ($comment ? ': ' . $comment : ''),
                $employeeId,
            );

            if ($decision !== 'approve') {
                // Remaining stages are marked skipped rather than left pending,
                // so a rejected request does not sit in anyone's queue.
                $request->approvals()
                    ->where('status', 'pending')
                    ->update(['status' => 'skipped']);

                $request->update(['status' => 'rejected', 'completed_at' => now()]);

                return $request->fresh('approvals');
            }

            $remaining = $request->approvals()->where('status', 'pending')->exists();

            if (! $remaining) {
                $request->update([
                    'status'         => 'approved',
                    'completed_at'   => now(),
                    // What Finance approved, which may be less than was asked.
                    'total_approved' => $request->items()->sum(DB::raw('COALESCE(amount_approved, amount_claimed)')),
                ]);
            }

            return $request->fresh(['approvals', 'items']);
        });
    }

    /** Inclusive of both travel days: a same-day trip is one day, not zero. */
    private function days($from, $to): int
    {
        return max(1, \Carbon\Carbon::parse($from)->diffInDays(\Carbon\Carbon::parse($to)) + 1);
    }

    /**
     * A human-facing reference, unique within the organisation.
     *
     * Sequential per organisation and year rather than globally, so one
     * tenant's numbering does not reveal another's volume.
     */
    private function reference(TravelRequest $request): string
    {
        $year = now()->year;

        $count = TravelRequest::withoutGlobalScopes()
            ->where('organization_id', $request->organization_id)
            ->whereYear('created_at', $year)
            ->count();

        return sprintf('TR-%d-%04d', $year, $count);
    }
}
