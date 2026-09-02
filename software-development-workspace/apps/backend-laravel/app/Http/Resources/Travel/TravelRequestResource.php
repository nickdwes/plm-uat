<?php

namespace App\Http\Resources\Travel;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TravelRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'reference' => $this->reference,
            'status'    => $this->status,

            'employee'  => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee?->id, 'name' => $this->employee?->full_name,
            ]),
            'account'     => $this->whenLoaded('account', fn () => [
                'id' => $this->account?->id, 'name' => $this->account?->name,
            ]),
            'project_id'  => $this->project_id,
            'project'     => $this->whenLoaded('project', fn () => [
                'id' => $this->project?->id, 'name' => $this->project?->name,
            ]),
            'destination' => $this->whenLoaded('destination', fn () => [
                'id' => $this->destination?->id, 'name' => $this->destination?->name,
            ]),

            'travel_mode'    => $this->travel_mode,
            'departure_date' => $this->departure_date?->toDateString(),
            'return_date'    => $this->return_date?->toDateString(),
            'days'           => $this->days,
            'purpose'         => $this->purpose,
            'business_impact' => $this->business_impact,

            'currency'         => $this->currency,
            'total_claimed'    => $this->total_claimed,
            'total_approved'   => $this->total_approved,
            'advance_required' => $this->advance_required,

            'priority'             => $this->priority,
            'accommodation_needed' => $this->accommodation_needed,
            'flexible_days'        => $this->flexible_days,
            'advance_justification'=> $this->advance_justification,
            'linked_task_ref'      => $this->linked_task_ref,

            'items'     => TravelRequestItemResource::collection($this->whenLoaded('items')),
            'travel_type'    => $this->travel_type,
            'transport_legs' => $this->whenLoaded('transportLegs', fn () => $this->transportLegs->map(function ($leg) {
                $calc = app(\App\Services\Travel\TransportCalculator::class);
                $r = $calc->reconcile($leg);

                return $leg->toArray() + [
                    'vehicle_type' => $leg->vehicleType?->name,
                    // What was approved against what happened. Null variance
                    // is "not yet settled", which is a different statement
                    // from "spent exactly what was approved".
                    'actual_value' => $r['actual'],
                    'variance'     => $r['variance'],
                    'basis'        => $r['basis'],
                ];
            })),
            'delivery_reference' => $this->delivery_reference,
            'delivery_note_attachment_id' => $this->delivery_note_attachment_id,
            'manifest'    => $this->whenLoaded('manifest'),
            'attachments' => $this->whenLoaded('attachments'),
            'couriers'    => $this->whenLoaded('couriers', fn () => $this->couriers->map(fn ($c) => [
                'id'         => $c->id,
                'employee'   => ['id' => $c->employee?->id, 'name' => $c->employee?->full_name],
                'is_primary' => $c->is_primary,
            ])),
            'itinerary' => $this->whenLoaded('itinerary'),
            'bookings'  => $this->whenLoaded('bookings'),
            'activity'  => $this->whenLoaded('activity', fn () => $this->activity->map(fn ($a) => [
                'id'          => $a->id,
                'event'       => $a->event,
                'description' => $a->description,
                'actor'       => $a->actor?->full_name,
                'occurred_at' => $a->occurred_at?->toIso8601String(),
            ])),
            'approvals' => TravelApprovalResource::collection($this->whenLoaded('approvals')),

            /*
             * Whether this viewer may decide the stage now pending.
             *
             * Answered by the policy rather than re-derived in the client,
             * which would drift from it — the policy also honours named
             * delegates acting on a head's behalf, a rule the browser has no
             * way to know.
             */
            'can_decide' => $request->user()?->can('decide', $this->resource) ?? false,

            'reconciled_at' => $this->reconciled_at?->toIso8601String(),
            'can_close'     => $request->user()?->hasPermission(\App\Support\Authorization\Permission::TRAVEL_ASSESS_FINANCE) ?? false,

            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
