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
            'location'        => $this->location,
            'holder'          => $this->whenLoaded('holder', fn () => [
                'id'              => $this->holder->id,
                'full_name'       => trim($this->holder->first_name . ' ' . $this->holder->last_name),
                'employee_number' => $this->holder->employee_number,
            ]),
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
            'expires_on'     => $this->expires_on?->toDateString(),
            'days_to_expiry' => $this->daysToExpiry(),
            'auto_renews'    => (bool) $this->auto_renews,
            'seats_total'    => $this->seats_total,
            'seats_used'     => $this->seats_used,
            'seats_spare'    => $this->seatsSpare(),

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

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
