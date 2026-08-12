<?php

namespace App\Services\Asset;

use App\Models\Asset\Asset;
use App\Models\Asset\AssetEvent;
use Illuminate\Support\Facades\DB;

/**
 * Moving an asset through its life, and leaving a record of it.
 *
 * One way in, because the alternative is what the register had before: a status
 * column set in half a dozen places, a `lifecycle_stage` string set in a
 * different half dozen, and no way to reconcile the two when they disagreed.
 *
 * Everything here writes the event first and updates the register from it. The
 * event is the fact — this happened, on this day, to this person — and the
 * asset's status is a summary of the most recent one. Deriving the summary from
 * the facts means they cannot drift; setting them separately means they will.
 */
class AssetLifecycle
{
    /**
     * Record something that happened, and bring the register into line.
     *
     * @param array{employee_id?:int|null, location?:string|null, vendor_partner_id?:int|null, note?:string|null, occurred_on?:string|null} $context
     */
    public function record(
        Asset $asset,
        string $event,
        array $context = [],
        ?int $recordedBy = null,
    ): AssetEvent {
        abort_unless(in_array($event, AssetEvent::EVENTS, true), 422, "Unknown asset event: {$event}");

        return DB::transaction(function () use ($asset, $event, $context, $recordedBy) {
            $occurredOn = $context['occurred_on'] ?? now()->toDateString();

            $record = $asset->events()->create([
                'organization_id'   => $asset->organization_id,
                'event'             => $event,
                'occurred_on'       => $occurredOn,
                'employee_id'       => $context['employee_id'] ?? null,
                'location'          => $context['location'] ?? null,
                'vendor_partner_id' => $context['vendor_partner_id'] ?? null,
                'note'              => $context['note'] ?? null,
                'recorded_by'       => $recordedBy,
            ]);

            $this->syncRegister($asset, $event, $context, $occurredOn);

            return $record;
        });
    }

    /**
     * Hand an asset to somebody.
     *
     * Assigning something already held is a reassignment, not a second
     * assignment. The distinction is the whole reason the timeline is worth
     * drawing: a handover between two people is where the paperwork goes
     * missing and the condition never gets checked, and two identical
     * "assigned" markers hide precisely that.
     */
    public function assign(Asset $asset, int $employeeId, array $context = [], ?int $recordedBy = null): AssetEvent
    {
        $event = $asset->assigned_to !== null && $asset->assigned_to !== $employeeId
            ? AssetEvent::REASSIGNED
            : AssetEvent::ASSIGNED;

        return $this->record($asset, $event, $context + ['employee_id' => $employeeId], $recordedBy);
    }

    /** Take it back. Who returned it is worth recording; who has it now is not. */
    public function release(Asset $asset, array $context = [], ?int $recordedBy = null): AssetEvent
    {
        return $this->record(
            $asset,
            AssetEvent::RETURNED,
            $context + ['employee_id' => $asset->assigned_to],
            $recordedBy,
        );
    }

    /**
     * The register, brought into line with what just happened.
     *
     * Only the events that genuinely change custody or status touch it.
     * `repaired` deliberately does not: whether a mended laptop goes back to
     * its old holder or onto the shelf is a decision somebody makes, and
     * guessing here would put it silently back on a desk it may have left.
     */
    private function syncRegister(Asset $asset, string $event, array $context, string $occurredOn): void
    {
        $changes = [];

        if ($status = AssetEvent::STATUS_AFTER[$event] ?? null) {
            $changes['status'] = $status;
        }

        // `lifecycle_stage` is what the designs' status chip reads, so it
        // follows the event name rather than being set by hand somewhere else.
        $changes['lifecycle_stage'] = $event;

        if (in_array($event, [AssetEvent::ASSIGNED, AssetEvent::REASSIGNED], true)) {
            $changes['assigned_to']   = $context['employee_id'] ?? null;
            $changes['assigned_date'] = $occurredOn;
        }

        /*
         * Custody ends on return, loss, retirement and disposal alike.
         *
         * A retired laptop still showing an owner is how somebody gets chased
         * at their exit interview for a machine that was scrapped last year.
         */
        if (in_array($event, [
            AssetEvent::RETURNED, AssetEvent::LOST,
            AssetEvent::RETIRED, AssetEvent::DISPOSED,
        ], true)) {
            $changes['assigned_to']   = null;
            $changes['assigned_date'] = null;
        }

        if ($event === AssetEvent::IN_STOCK) {
            $changes['in_stock_date'] = $asset->in_stock_date?->toDateString() ?? $occurredOn;
        }

        if ($event === AssetEvent::PROCURED) {
            $changes['procured_date'] = $asset->procured_date?->toDateString() ?? $occurredOn;
        }

        if (isset($context['location'])) {
            $changes['location'] = $context['location'];
        }

        $asset->update($changes);
    }
}
