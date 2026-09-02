<?php

namespace App\Services\Project;

use App\Models\Procurement\ProcurementRequest;
use App\Models\Project\ProjectBom;
use App\Models\Project\ProjectBomItem;
use App\Models\Project\ProjectMaterialOrder;
use App\Models\Project\ProjectOrder;
use App\Models\Project\ProjectTravelRequest;
use App\Models\Project\ProjectVendorEngagement;
use App\Support\Account\ReferenceSequence;

/**
 * Turns an approved BOM into order records, and routes each to the module that
 * fulfils it. One order per BOM line, keyed on the line so re-running is
 * idempotent. A line whose fulfilment needs input the BOM cannot give
 * (training → trainees, travel → task cycles) gets an order but no spawned
 * record — it is raised/linked by hand later, the same way task prerequisites
 * raise their records.
 */
class OrderGenerator
{
    /** BOM category → project procurement category, for the lines that buy. */
    private const PROCURE_CATEGORY = [
        'software'           => 'software',
        'cloud_subscription' => 'subscription',
        'support_services'   => 'service',
    ];

    /** BOM category → vendor-engagement type. */
    private const ENGAGEMENT_TYPE = [
        'professional_services' => 'professional_services',
        'third_party_services'  => 'external_vendor',
    ];

    /**
     * Generate orders for every line of an approved BOM.
     *
     * @return array{created:int,existing:int,spawned:int}
     */
    public function generate(ProjectBom $bom, ?int $actorId = null): array
    {
        $tally = ['created' => 0, 'existing' => 0, 'spawned' => 0];

        foreach ($bom->items()->get() as $item) {
            $order = ProjectOrder::where('bom_item_id', $item->id)->first();
            if ($order) {
                $tally['existing']++;
                continue;
            }

            $order = ProjectOrder::create([
                'organization_id' => $item->organization_id,
                'project_id'      => $item->project_id,
                'bom_item_id'     => $item->id,
                'reference'       => ReferenceSequence::next('ORD', 'project_orders', $item->organization_id),
                'category'        => $item->category,
                'description'     => $item->description,
                'part_number'     => $item->part_number,
                'vendor_partner_id' => $item->vendor_partner_id,
                'quantity'        => $item->quantity,
                'unit_cost'       => $item->unit_cost,
                'selling_price'   => $item->selling_price,
                'order_value'     => $item->order_value,
                'margin'          => $item->margin,
                'currency'        => strtoupper($item->currency),
                'expected_delivery_date' => $item->expected_delivery_date,
                'phase_id'        => $item->phase_id,
                'created_by'      => $actorId,
            ]);
            $tally['created']++;

            if ($this->spawnFor($order, $item, $actorId)) {
                $tally['spawned']++;
            }
        }

        return $tally;
    }

    /**
     * Create the execution record this order routes to and link it. Returns
     * true when something was spawned. Training and travel spawn nothing here.
     */
    private function spawnFor(ProjectOrder $order, ProjectBomItem $item, ?int $actorId): bool
    {
        $orgId = $item->organization_id;

        // Hardware & logistics → a supplier material order (the Orders register).
        if (in_array($item->category, ['hardware', 'logistics_cost'], true)) {
            $mo = ProjectMaterialOrder::create([
                'organization_id' => $orgId,
                'project_id'      => $item->project_id,
                'reference'       => ReferenceSequence::next('MO', 'project_material_orders', $orgId),
                'name'            => $item->description,
                'description'     => $item->part_number ? "Part {$item->part_number}" : null,
                'vendor_partner_id' => $item->vendor_partner_id,
                'supplier_name'   => $item->supplier_name,
                'status'          => ProjectMaterialOrder::STATUS_PENDING,
                'created_by'      => $actorId,
            ]);

            return $this->link($order, $mo);
        }

        // Software / subscription / support → a procurement request.
        if (isset(self::PROCURE_CATEGORY[$item->category])) {
            $pr = ProcurementRequest::create([
                'organization_id' => $orgId,
                'reference'       => ReferenceSequence::next('PR', 'procurement_requests', $orgId),
                'context'         => 'project',
                'project_id'      => $item->project_id,
                'item'            => $item->description,
                'category'        => self::PROCURE_CATEGORY[$item->category],
                'quantity'        => max(1, (int) round((float) $item->quantity)),
                'vendor'          => $item->supplier_name,
                'vendor_partner_id' => $item->vendor_partner_id,
                'estimated_cost'  => round((float) $item->unit_cost * (float) $item->quantity, 2),
                'currency'        => strtoupper($item->currency),
                'notes'           => 'Generated from the BOM.',
                'created_by'      => $actorId,
            ]);

            return $this->link($order, $pr);
        }

        // Professional services / third party → a vendor engagement.
        if (isset(self::ENGAGEMENT_TYPE[$item->category])) {
            $eng = ProjectVendorEngagement::create([
                'organization_id' => $orgId,
                'project_id'      => $item->project_id,
                'reference'       => ReferenceSequence::next('VE', 'project_vendor_engagements', $orgId),
                'engagement_type' => self::ENGAGEMENT_TYPE[$item->category],
                'vendor_partner_id' => $item->vendor_partner_id,
                'vendor_name'     => $item->supplier_name,
                'scope'           => $item->description,
                'status'          => ProjectVendorEngagement::STATUS_IDENTIFYING,
                'created_by'      => $actorId,
            ]);

            return $this->link($order, $eng);
        }

        // training, travel_accommodation → left for a raise/link action.
        return false;
    }

    /**
     * Raise the execution record for an order that generation left unrouted —
     * training and travel, which need input the BOM cannot give. Idempotent:
     * an already-linked order is returned untouched.
     *
     * Training is procured as a service from its provider; travel becomes a
     * project travel request. (Deep training/travel wiring — trainee registers,
     * task cycles — remains a later step; this gives the order a real, tracked,
     * approvable record now.)
     */
    public function raise(ProjectOrder $order, ?int $actorId = null): ProjectOrder
    {
        if ($order->fulfillable_id) {
            return $order;
        }

        $orgId = $order->organization_id;

        if ($order->category === 'training') {
            // Activate the training order into an enablement plan (course + cost
            // per person + trainee register). The details are filled on the
            // project's Training tab.
            $tf = \App\Models\Project\ProjectTrainingFulfillment::create([
                'organization_id' => $orgId,
                'project_id'      => $order->project_id,
                'order_id'        => $order->id,
                'title'           => $order->description,
                'cost_per_person' => round((float) $order->unit_cost, 2),
                'currency'        => strtoupper($order->currency),
                'status'          => 'planned',
                'created_by'      => $actorId,
            ]);
            $this->link($order, $tf);

            return $order->fresh();
        }

        if ($order->category === 'travel_accommodation') {
            $tr = ProjectTravelRequest::create([
                'organization_id' => $orgId,
                'project_id'      => $order->project_id,
                'reference'       => ReferenceSequence::next('TR', 'project_travel_requests', $orgId),
                'purpose'         => $order->description,
                'estimated_cost'  => round((float) $order->unit_cost * (float) $order->quantity, 2),
                'currency'        => strtoupper($order->currency),
                'tasks'           => [],
                'personnel'       => [],
                'created_by'      => $actorId,
            ]);
            $this->link($order, $tr);

            return $order->fresh();
        }

        abort(422, 'This order has nothing to raise.');
    }

    private function link(ProjectOrder $order, object $record): bool
    {
        $order->update([
            'fulfillable_type' => $record::class,
            'fulfillable_id'   => $record->id,
            'status'           => ProjectOrder::STATUS_ORDERED,
            'ordered_at'       => now(),
        ]);

        return true;
    }
}
