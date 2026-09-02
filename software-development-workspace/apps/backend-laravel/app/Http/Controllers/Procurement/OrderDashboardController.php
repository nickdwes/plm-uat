<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\Project\ProjectOrder;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The cross-project order dashboard — every order generated from any project's
 * BOM, in one place, so procurement sees the whole fulfilment pipeline rather
 * than one project at a time. Read-only: an order is worked on its project.
 */
class OrderDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permission::ASSETS_VIEW), 403);

        $orders = ProjectOrder::query()
            ->with(['project:id,name', 'vendorPartner:id,name', 'fulfillable'])
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('category')->toString(), fn ($q, $c) => $q->where('category', $c))
            ->when($request->integer('project_id'), fn ($q, $id) => $q->where('project_id', $id))
            ->when($request->string('q')->toString(), fn ($q, $t) => $q->where('description', 'ilike', "%{$t}%"))
            ->latest('id')->get();

        $delivered = [ProjectOrder::STATUS_DELIVERED, ProjectOrder::STATUS_ACCEPTED, ProjectOrder::STATUS_CLOSED];

        return response()->json([
            'data' => $orders->map(fn (ProjectOrder $o) => [
                'id'          => $o->id,
                'reference'   => $o->reference,
                'project'     => $o->project ? ['id' => $o->project->id, 'name' => $o->project->name] : null,
                'category'    => $o->category,
                'description' => $o->description,
                'supplier'    => $o->vendorPartner?->name,
                'order_value' => (float) $o->order_value,
                'margin'      => (float) $o->margin,
                'currency'    => strtoupper($o->currency),
                'status'      => $o->status,
                'fulfilled_by' => $this->fulfillableInfo($o),
                'expected_delivery_date' => $o->expected_delivery_date?->toDateString(),
            ]),
            'summary' => [
                'count'        => $orders->count(),
                'projects'     => $orders->pluck('project_id')->unique()->count(),
                'active'       => $orders->whereNotIn('status', [ProjectOrder::STATUS_CANCELLED, ProjectOrder::STATUS_CLOSED])->count(),
                'delivered'    => $orders->whereIn('status', $delivered)->count(),
                'unrouted'     => $orders->whereNull('fulfillable_id')->whereNotIn('status', [ProjectOrder::STATUS_CANCELLED])->count(),
                'total_value'  => round((float) $orders->sum('order_value'), 2),
                'total_margin' => round((float) $orders->sum('margin'), 2),
                'by_status'    => $orders->groupBy('status')->map->count(),
                'by_category'  => $orders->groupBy('category')->map->count(),
            ],
            'options' => [
                'statuses'   => ProjectOrder::STATUSES,
                'categories' => \App\Models\Project\ProjectBomItem::CATEGORIES,
                'projects'   => $orders->map(fn ($o) => $o->project)->filter()->unique('id')->values()
                    ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]),
            ],
        ]);
    }

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

        return ['kind' => $kind, 'reference' => $rec->reference ?? null, 'status' => $rec->status ?? ($rec->approval_status ?? null)];
    }
}
