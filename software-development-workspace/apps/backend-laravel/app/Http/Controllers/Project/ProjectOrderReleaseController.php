<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectBom;
use App\Services\Project\OrderGenerator;
use App\Services\Project\OrderReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * When a project may begin ordering — the gate, the commercial signals behind
 * it, and the customer LPO. Changing a setting that turns the gate green
 * releases the orders straight away, so award/contract/LPO drives generation
 * without anyone re-pressing a button.
 */
class ProjectOrderReleaseController extends Controller
{
    public function show(Project $project, OrderReadiness $readiness): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($this->payload($project, $readiness));
    }

    public function update(Request $request, Project $project, OrderReadiness $readiness, OrderGenerator $generator): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'order_release_gate'      => ['sometimes', Rule::in(OrderReadiness::GATES)],
            'contract_ready_override' => ['sometimes', 'nullable', 'boolean'],
            'customer_lpo_number'     => ['sometimes', 'nullable', 'string', 'max:120'],
            'customer_lpo_date'       => ['sometimes', 'nullable', 'date'],
            'customer_lpo_value'      => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'customer_lpo_currency'   => ['sometimes', 'nullable', 'string', 'size:3'],
        ]);

        $project->update($data);

        // If the gate is now satisfied and an approved BOM is waiting, release it.
        $generated = null;
        $bom = $project->boms()->where('status', ProjectBom::STATUS_APPROVED)->latest('id')->first();
        if ($bom && $readiness->ready($project->fresh())) {
            $generated = $generator->generate($bom, $request->user()->id);
        }

        return response()->json($this->payload($project->fresh(), $readiness) + ['generated' => $generated]);
    }

    private function payload(Project $project, OrderReadiness $readiness): array
    {
        return [
            'readiness' => $readiness->for($project),
            'settings'  => [
                'order_release_gate'      => $project->order_release_gate ?: 'manual',
                'contract_ready_override' => $project->contract_ready_override,
            ],
            'customer_lpo' => [
                'number'   => $project->customer_lpo_number,
                'date'     => $project->customer_lpo_date?->toDateString(),
                'value'    => $project->customer_lpo_value !== null ? (float) $project->customer_lpo_value : null,
                'currency' => $project->customer_lpo_currency,
            ],
            'bom_approved'    => $project->boms()->where('status', ProjectBom::STATUS_APPROVED)->exists(),
            'orders_exist'    => $project->orders()->exists(),
        ];
    }
}
