<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The order-lifecycle records generated from a project's BOM — one queue over
 * everything the project is fulfilling, each showing where its linked module
 * record stands.
 */
class ProjectOrderController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($this->payload($project));
    }

    /** Advance an order's lifecycle status; the entered state stamps its date. */
    public function updateStatus(Request $request, Project $project, ProjectOrder $order): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($order->project_id === $project->id, 404);

        $data = $request->validate([
            'status'          => ['required', Rule::in(ProjectOrder::STATUSES)],
            'acceptance_note' => ['nullable', 'string', 'max:500'],
        ]);

        $patch = ['status' => $data['status']];
        if ($stamp = ProjectOrder::STATUS_STAMP[$data['status']] ?? null) {
            $patch[$stamp] = $order->{$stamp} ?? now();
        }
        // Customer acceptance records who signed it off and any note.
        if ($data['status'] === ProjectOrder::STATUS_ACCEPTED) {
            $patch['accepted_by'] = $request->user()->id;
            if (array_key_exists('acceptance_note', $data)) {
                $patch['acceptance_note'] = $data['acceptance_note'];
            }
        }
        $order->update($patch);

        return response()->json($this->payload($project));
    }

    /** Generate orders from the approved BOM now — refused unless the gate is green. */
    public function generate(Request $request, Project $project, \App\Services\Project\OrderGenerator $generator, \App\Services\Project\OrderReadiness $readiness): JsonResponse
    {
        $this->authorize('update', $project);

        $bom = $project->boms()->where('status', \App\Models\Project\ProjectBom::STATUS_APPROVED)->latest('id')->first();
        abort_unless($bom, 422, 'Approve a BOM first.');
        abort_unless($readiness->ready($project), 422, $readiness->for($project)['blocker'] ?? 'Ordering is not yet released.');

        $generator->generate($bom, $request->user()->id);

        return response()->json($this->payload($project));
    }

    /** Raise the fulfilment record for an order generation left unrouted (training/travel). */
    public function raise(Project $project, ProjectOrder $order, \App\Services\Project\OrderGenerator $generator): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($order->project_id === $project->id, 404);

        $generator->raise($order, request()->user()->id);

        return response()->json($this->payload($project));
    }

    public function destroy(Project $project, ProjectOrder $order): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($order->project_id === $project->id, 404);

        $order->delete();

        return response()->json($this->payload($project));
    }

    private function payload(Project $project): array
    {
        $orders = $project->orders()->with(['vendorPartner:id,name', 'fulfillable', 'acceptedBy:id,name'])->get();

        $active = $orders->whereNotIn('status', [ProjectOrder::STATUS_CANCELLED, ProjectOrder::STATUS_CLOSED]);

        return [
            'data' => $orders->map(fn (ProjectOrder $o) => [
                'id'          => $o->id,
                'reference'   => $o->reference,
                'category'    => $o->category,
                'description' => $o->description,
                'part_number' => $o->part_number,
                'supplier'    => $o->vendorPartner?->name,
                'quantity'    => (float) $o->quantity,
                'unit_cost'   => (float) $o->unit_cost,
                'order_value' => (float) $o->order_value,
                'margin'      => (float) $o->margin,
                'currency'    => strtoupper($o->currency),
                'status'      => $o->status,
                'expected_delivery_date' => $o->expected_delivery_date?->toDateString(),
                'fulfilled_by' => $this->fulfillableInfo($o),
                'can_raise'    => $o->fulfillable_id === null && in_array($o->category, ['training', 'travel_accommodation'], true),
                'accepted_by'  => $o->acceptedBy?->name,
                'acceptance_note' => $o->acceptance_note,
            ])->values(),
            'summary' => [
                'count'        => $orders->count(),
                'active'       => $active->count(),
                'delivered'    => $orders->whereIn('status', [ProjectOrder::STATUS_DELIVERED, ProjectOrder::STATUS_ACCEPTED, ProjectOrder::STATUS_CLOSED])->count(),
                'unlinked'     => $orders->whereNull('fulfillable_id')->whereNotIn('status', [ProjectOrder::STATUS_CANCELLED])->count(),
                'total_value'  => round((float) $orders->sum('order_value'), 2),
                'total_margin' => round((float) $orders->sum('margin'), 2),
                'by_status'    => $orders->groupBy('status')->map->count(),
            ],
        ];
    }

    /** A human handle for the linked execution record, if any. */
    private function fulfillableInfo(ProjectOrder $o): ?array
    {
        if (! $o->fulfillable) {
            return null;
        }

        $rec = $o->fulfillable;
        $kind = match ($o->fulfillable_type) {
            \App\Models\Project\ProjectMaterialOrder::class    => 'material_order',
            \App\Models\Procurement\ProcurementRequest::class  => 'procurement',
            \App\Models\Project\ProjectVendorEngagement::class => 'engagement',
            \App\Models\Project\ProjectTravelRequest::class    => 'travel',
            \App\Models\Project\ProjectTrainingFulfillment::class => 'training',
            default => 'record',
        };

        return [
            'kind'      => $kind,
            'reference' => $rec->reference ?? null,
            'status'    => $rec->status ?? ($rec->approval_status ?? null),
        ];
    }
}
