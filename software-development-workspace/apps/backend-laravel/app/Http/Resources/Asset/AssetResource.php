<?php

namespace App\Http\Resources\Asset;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'asset_tag'       => $this->asset_tag,
            'category'        => $this->category,
            'brand'           => $this->brand,
            'model'           => $this->model,
            'serial_number'   => $this->serial_number,

            'status'          => $this->status,
            'lifecycle_stage' => $this->lifecycle_stage,
            'condition'       => $this->condition,

            // ── Custody ───────────────────────────────────────────────────
            'assigned_date'   => $this->assigned_date?->toDateString(),
            'expected_return_date' => $this->expected_return_date?->toDateString(),
            // What went out with it, so the return screen can tick it back off.
            'accessories'     => $this->assigned_accessories ?? [],
            'location'        => $this->location,
            'holder'          => $this->whenLoaded('holder', fn () => [
                'id'              => $this->holder->id,
                'full_name'       => trim($this->holder->first_name . ' ' . $this->holder->last_name),
                'employee_number' => $this->holder->employee_number,
            ]),
            /*
             * Who is answerable, for the things nobody carries.
             *
             * `uses_custodian` travels with it so the screen knows which of the
             * two questions to ask — offering "assign" on a core switch is how
             * racked equipment ends up on a leaver's return checklist.
             */
            'uses_custodian'  => $this->usesCustodian(),
            'custodian_since' => $this->custodian_since?->toDateString(),
            'custodian'       => $this->whenLoaded('custodian', fn () => $this->custodian ? [
                'id'              => $this->custodian->id,
                'full_name'       => trim($this->custodian->first_name . ' ' . $this->custodian->last_name),
                'employee_number' => $this->custodian->employee_number,
            ] : null),
            'department'      => $this->whenLoaded('department', fn () => [
                'id'   => $this->department->id,
                'name' => $this->department->name,
            ]),

            'handover_required'  => (bool) $this->handover_required,
            'handover_signed_at' => $this->handover_signed_at?->toIso8601String(),

            // ── Procurement ───────────────────────────────────────────────
            'purchase_date'        => $this->purchase_date?->toDateString(),
            'purchase_value'       => $this->purchase_value !== null ? (float) $this->purchase_value : null,
            'vendor'               => $this->vendor,
            'invoice_number'       => $this->invoice_number,
            'warranty_period'      => $this->warranty_period,
            'warranty_expiry_date' => $this->warranty_expiry_date?->toDateString(),
            'procured_date'        => $this->procured_date?->toDateString(),
            'in_stock_date'        => $this->in_stock_date?->toDateString(),
            'next_maintenance'     => $this->next_maintenance?->toDateString(),

            // ── Specification ─────────────────────────────────────────────
            'specification' => array_filter([
                'processor'        => $this->processor,
                'ram'              => $this->ram,
                'storage'          => $this->storage,
                'operating_system' => $this->operating_system,
            ], fn ($v) => $v !== null),

            'notes' => $this->notes,

            // Derived server-side so every client agrees on what "expired"
            // means, rather than each re-deriving it from a date.
            'is_assigned'           => $this->isAssigned(),
            'warranty_has_expired'  => $this->warrantyHasExpired(),
            'maintenance_is_due'    => $this->maintenanceIsDue(),

            // What kind of thing this is, and the questions that only apply to
            // some kinds. Null seats on a laptop is not nought seats.
            'asset_class'    => $this->asset_class,
            'description'    => $this->description,
            'starts_on'      => $this->starts_on?->toDateString(),
            'expires_on'     => $this->expires_on?->toDateString(),
            'days_to_expiry' => $this->daysToExpiry(),
            'auto_renews'    => (bool) $this->auto_renews,
            'seats_total'    => $this->seats_total,
            // Counted from allocations. Nobody types this.
            'seats_used'     => $this->isSeated() ? $this->seatsUsed() : null,
            'seats_spare'    => $this->seatsSpare(),

            /*
             * Who is affected — the question a typed count could never answer,
             * and the reason a renewal or an exit interview needs this at all.
             */
            'seat_holders' => $this->whenLoaded('seats', fn () => $this->seats
                ->whereNull('released_on')
                ->map(fn ($a) => [
                    'id'           => $a->id,
                    'employee_id'  => $a->employee_id,
                    'employee'     => $a->employee?->full_name,
                    'allocated_on' => $a->allocated_on?->toDateString(),
                    'note'         => $a->note,
                ])->values()),

            /*
             * The lifecycle timeline.
             *
             * Only when loaded — the list endpoint returns hundreds of assets
             * and none of them are drawing a timeline.
             */
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'id'          => $e->id,
                'event'       => $e->event,
                'occurred_on' => $e->occurred_on?->toDateString(),
                'employee'    => $e->employee?->full_name,
                'location'    => $e->location,
                'vendor'      => $e->vendor?->name,
                'note'        => $e->note,
            ])),

            /*
             * The answers, and the questions they belong to.
             *
             * The definitions come back beside the asset on a single fetch —
             * see the controller — rather than as a relation, because they are
             * matched on category name and class, not a foreign key.
             */
            'asset_category_id' => $this->asset_category_id,
            'parent_asset_id'   => $this->parent_asset_id,
            'custom_fields' => (object) ($this->custom_fields ?? []),

            'end_of_support_on' => $this->end_of_support_on?->toDateString(),
            'is_unsupported'    => $this->isUnsupported(),

            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($d) => [
                'id' => $d->id, 'category' => $d->category, 'note' => $d->note,
                'original_name' => $d->original_name, 'size_bytes' => $d->size_bytes,
                'uploaded_at' => $d->created_at?->toDateString(),
            ])->values()),

            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($p) => [
                'id' => $p->id, 'caption' => $p->caption,
                'is_primary' => $p->is_primary, 'at_handover' => $p->at_handover,
                'original_name' => $p->original_name,
                'uploaded_at' => $p->created_at?->toDateString(),
            ])->values()),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
