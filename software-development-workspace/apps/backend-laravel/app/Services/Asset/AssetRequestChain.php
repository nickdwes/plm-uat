<?php

namespace App\Services\Asset;

use App\Models\Asset\Asset;
use App\Models\Asset\AssetRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Moving a request from asking to having.
 *
 * One place, because the stages are an order and an order kept in several
 * places eventually disagrees with itself. Every method here records *who*
 * acted as well as what happened — a request approved by HR and then declined
 * by finance used to read as though HR had declined it, because a single
 * `approved_by` can only remember the last person to touch it.
 */
class AssetRequestChain
{
    /**
     * Move it to the next stage.
     *
     * Refuses to advance something already settled. Approving a rejected
     * request is not a correction, it is two people disagreeing in the record,
     * and the fix is a new request rather than overwriting the old decision.
     */
    public function advance(AssetRequest $request, User $actor, ?string $note = null): AssetRequest
    {
        abort_if($request->isClosed(), 422, 'That request is already settled.');

        /*
         * These columns name employees, not accounts — as every "who did this"
         * column in this module does. Somebody acting without an employee
         * record leaves the stamp null rather than writing an id that resolves
         * to the wrong person.
         */
        $actorId = $actor->employeeId();

        $next = AssetRequest::NEXT_STAGE[$request->status] ?? null;
        abort_if($next === null, 422, "A request at {$request->status} has nowhere further to go.");

        return DB::transaction(function () use ($request, $actorId, $note, $next) {
            $stamps = ['status' => $next];

            // Whoever moved it out of a stage is who acted at that stage.
            $stamps += match ($request->status) {
                AssetRequest::STATUS_PENDING => [
                    'hr_reviewed_by' => $actorId, 'hr_reviewed_at' => now(),
                ],
                AssetRequest::STATUS_HR_REVIEW => [
                    'hr_reviewed_by' => $request->hr_reviewed_by ?? $actorId,
                    'hr_reviewed_at' => $request->hr_reviewed_at ?? now(),
                ],
                AssetRequest::STATUS_AWAITING_FINANCE => [
                    'finance_reviewed_by' => $actorId, 'finance_reviewed_at' => now(),
                ],
                AssetRequest::STATUS_PROCUREMENT => [
                    'procurement_by' => $actorId, 'procurement_at' => now(),
                ],
                default => [],
            };

            if ($next === AssetRequest::STATUS_APPROVED) {
                $stamps['approved_by'] = $actorId;
                $stamps['approved_at'] = now();
            }

            if ($note !== null) {
                $stamps['decision_note'] = $note;
            }

            $request->update($stamps);

            return $request->fresh();
        });
    }

    /**
     * Turn it down, with a reason.
     *
     * The reason is required and is what the requester is shown. A refusal
     * without one is the thing people actually complain about — not the
     * refusal itself, but not being told why.
     */
    public function decline(AssetRequest $request, User $actor, string $reason): AssetRequest
    {
        abort_if($request->isClosed(), 422, 'That request is already settled.');
        abort_if(trim($reason) === '', 422, 'Say why it was declined.');

        $request->update([
            'status'           => AssetRequest::STATUS_REJECTED,
            'rejection_reason' => trim($reason),
            'approved_by'      => $actor->employeeId(),
            'approved_at'      => now(),
        ]);

        return $request->fresh();
    }

    /**
     * Send it to procurement because there is nothing suitable to give.
     *
     * A separate act from approving. The request has been agreed to and cannot
     * be fulfilled from stock, which is a different situation from waiting on
     * a decision — and the designs show it as its own banner for that reason.
     */
    public function forwardToProcurement(AssetRequest $request, User $actor, ?string $note = null): AssetRequest
    {
        abort_if($request->isClosed(), 422, 'That request is already settled.');

        $request->update([
            'status'           => AssetRequest::STATUS_PROCUREMENT,
            'procurement_by'   => $actor->employeeId(),
            'procurement_at'   => now(),
            'procurement_note' => $note ?: 'No matching assets available in inventory.',
        ]);

        return $request->fresh();
    }

    /**
     * Give them the thing.
     *
     * The request is closed and the asset waits at pending acceptance rather
     * than being handed over on paper — custody starts when somebody says they
     * have it, not when somebody types their name.
     */
    public function fulfil(
        AssetRequest $request,
        Asset $asset,
        User $actor,
        int $employeeId,
        ?string $note = null,
    ): AssetRequest {
        abort_if($request->isClosed(), 422, 'That request is already settled.');
        abort_unless(
            $asset->status === Asset::STATUS_AVAILABLE,
            422,
            'That asset is not available to give out.',
        );

        return DB::transaction(function () use ($request, $asset, $actor, $employeeId, $note) {
            $asset->update([
                'status'            => Asset::STATUS_PENDING_ACCEPTANCE,
                'assigned_to'       => $employeeId,
                'assigned_date'     => now()->toDateString(),
                'handover_required' => true,
                'handover_signed_at' => null,
                'accepted_by'       => null,
            ]);

            $request->update([
                'status'        => AssetRequest::STATUS_FULFILLED,
                'asset_id'      => $asset->id,
                'decision_note' => $note ?? $request->decision_note,
            ]);

            return $request->fresh();
        });
    }

    /**
     * The person says they have it, and in what condition.
     *
     * This is the moment custody actually begins. Anything the recipient notes
     * — a scratch already on the screen — belongs on the record from the start
     * rather than being discovered at an exit interview.
     */
    public function accept(Asset $asset, int $employeeId, ?string $note = null): Asset
    {
        abort_unless(
            $asset->status === Asset::STATUS_PENDING_ACCEPTANCE,
            422,
            'That asset is not waiting to be accepted.',
        );

        abort_unless(
            (int) $asset->assigned_to === $employeeId,
            403,
            'It was allocated to somebody else.',
        );

        $asset->update([
            'status'             => Asset::STATUS_ASSIGNED,
            'handover_signed_at' => now(),
            'accepted_by'        => $employeeId,
            'acceptance_note'    => $note,
        ]);

        return $asset->fresh();
    }
}
