<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Project\Project;
use App\Models\Project\ProjectTravelRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The approval tracker — one queue over everything on a project that waits on a
 * sign-off: travel requests and procurement items today. It reads the two
 * registers into a single, sortable list so an approver sees what is pending in
 * one place rather than tab by tab, and can decide without leaving it.
 *
 * The registers stay the source of truth; this controller only reads them and
 * records a decision (status + who + when) the same way each register's own
 * decide endpoint does.
 */
class ProjectApprovalController extends Controller
{
    private const KINDS = ['travel', 'procurement'];

    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $travel = $project->travelRequests()
            ->with('creator:id,name', 'decider:id,name')->get()
            ->map(fn (ProjectTravelRequest $r) => [
                'kind'        => 'travel',
                'id'          => $r->id,
                'reference'   => $r->reference,
                'title'       => $r->purpose,
                'detail'      => $this->travelDetail($r),
                'amount'      => $r->estimated_cost !== null ? (float) $r->estimated_cost : null,
                'currency'    => $r->currency,
                'needed_by'   => $r->start_date?->toDateString(),
                'requested_by'=> $r->creator?->name,
                'requested_on'=> $r->created_at?->toDateString(),
                'status'      => $r->approval_status,
                'decided_by'  => $r->decider?->name,
                'decided_at'  => $r->decided_at?->toDateTimeString(),
                'flags'       => $this->travelFlags($r),
            ]);

        $procurement = ProcurementRequest::where('project_id', $project->id)
            ->with('creator:id,name', 'decider:id,name')->get()
            ->map(fn (ProcurementRequest $r) => [
                'kind'        => 'procurement',
                'id'          => $r->id,
                'reference'   => $r->reference,
                'title'       => $r->item,
                'detail'      => $this->procurementDetail($r),
                'amount'      => $r->estimated_cost !== null ? (float) $r->estimated_cost : null,
                'currency'    => $r->currency,
                'needed_by'   => $r->required_by?->toDateString(),
                'requested_by'=> $r->creator?->name,
                'requested_on'=> $r->created_at?->toDateString(),
                'status'      => $r->approval_status,
                'decided_by'  => $r->decider?->name,
                'decided_at'  => $r->decided_at?->toDateTimeString(),
                'flags'       => $r->isLongLead() ? ['long-lead'] : [],
            ]);

        // Pending first, then most recently raised; a waiting decision is the point.
        $rows = $travel->concat($procurement)
            ->sortBy(fn ($r) => [$r['status'] === 'pending' ? 0 : 1, -$r['id']])
            ->values();

        return response()->json([
            'data'    => $rows,
            'summary' => $this->summary($rows),
        ]);
    }

    /** Decide one item, whichever register it lives in. */
    public function decide(Request $request, Project $project, string $kind, int $id): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless(in_array($kind, self::KINDS, true), 404);

        $data = $request->validate([
            'approval_status' => ['required', Rule::in(ProjectTravelRequest::APPROVAL_STATUSES)],
        ]);
        $decided = $data['approval_status'] !== 'pending';

        $model = $kind === 'travel'
            ? $project->travelRequests()->findOrFail($id)
            : ProcurementRequest::where('project_id', $project->id)->findOrFail($id);

        $model->update($data + [
            'decided_by' => $decided ? $request->user()->id : null,
            'decided_at' => $decided ? now() : null,
        ]);

        return $this->index($project);
    }

    // ── shape ────────────────────────────────────────────────────────────────

    private function summary($rows): array
    {
        $pending = $rows->where('status', 'pending');

        return [
            'total'         => $rows->count(),
            'pending'       => $pending->count(),
            'approved'      => $rows->where('status', 'approved')->count(),
            'rejected'      => $rows->where('status', 'rejected')->count(),
            'pending_value' => round((float) $pending->sum(fn ($r) => (float) ($r['amount'] ?? 0)), 2),
            'awaiting_costing' => $pending->whereNull('amount')->count(),
        ];
    }

    private function travelDetail(ProjectTravelRequest $r): string
    {
        $parts = array_filter([
            $r->location,
            $r->travel_days ? "{$r->travel_days} travel day" . ($r->travel_days === 1 ? '' : 's') : null,
            $r->personnel ? count($r->personnel) . ' travelling' : null,
        ]);

        return implode(' · ', $parts);
    }

    private function travelFlags(ProjectTravelRequest $r): array
    {
        $flags = [];
        if ($r->start_date && $r->approval_status === 'pending' && $r->start_date->lessThanOrEqualTo(now()->addDays(7))) {
            $flags[] = 'travel-imminent';
        }

        return $flags;
    }

    private function procurementDetail(ProcurementRequest $r): string
    {
        $parts = array_filter([
            $r->category,
            $r->quantity > 1 ? "×{$r->quantity}" . ($r->unit ? " {$r->unit}" : '') : null,
            $r->vendor,
            $r->lead_time_days ? "{$r->lead_time_days}-day lead" : null,
        ]);

        return implode(' · ', $parts);
    }
}
