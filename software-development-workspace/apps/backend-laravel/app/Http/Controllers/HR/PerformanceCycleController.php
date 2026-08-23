<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\PerformanceCycle;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Review cycles — named periods with dates, so reviews are comparable.
 */
class PerformanceCycleController extends Controller
{
    public function index(): JsonResponse
    {
        $this->may(Permission::PERFORMANCE_VIEW);

        return response()->json([
            'data' => PerformanceCycle::where('organization_id', app('current.organization_id'))
                ->orderByDesc('starts_on')
                ->get(['id', 'name', 'starts_on', 'ends_on', 'is_open']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->may(Permission::PERFORMANCE_MANAGE);

        $orgId = app('current.organization_id');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60',
                Rule::unique('performance_cycles')->where(fn ($q) => $q->where('organization_id', $orgId))],
            'starts_on' => ['required', 'date'],
            // A cycle that ends before it starts covers no days at all, and
            // every attendance figure in it would be empty for a reason
            // nobody would think to look for.
            'ends_on'   => ['required', 'date', 'after_or_equal:starts_on'],
            'is_open'   => ['sometimes', 'boolean'],
        ]);

        $cycle = PerformanceCycle::create($data + ['organization_id' => $orgId]);

        return response()->json(['data' => $cycle], 201);
    }

    public function update(Request $request, PerformanceCycle $performanceCycle): JsonResponse
    {
        $this->may(Permission::PERFORMANCE_MANAGE);

        $orgId = app('current.organization_id');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60',
                Rule::unique('performance_cycles')->ignore($performanceCycle->id)
                    ->where(fn ($q) => $q->where('organization_id', $orgId))],
            'starts_on' => ['sometimes', 'date'],
            'ends_on'   => ['sometimes', 'date'],
            'is_open'   => ['sometimes', 'boolean'],
        ]);

        $starts = $data['starts_on'] ?? $performanceCycle->starts_on->toDateString();
        $ends   = $data['ends_on'] ?? $performanceCycle->ends_on->toDateString();

        abort_if($ends < $starts, 422, 'A cycle cannot end before it starts.');

        /*
         * Moving the dates of a cycle that already has reviews would change
         * the window those attendance figures were taken over, without
         * changing the figures printed on them. Renaming and closing stay
         * allowed; the dates do not.
         */
        if ((isset($data['starts_on']) || isset($data['ends_on'])) && $performanceCycle->reviews()->exists()) {
            abort(422, 'This cycle already has reviews written against it, so its dates cannot move — '
                . 'the attendance on those reviews was measured over the window as it stands.');
        }

        $performanceCycle->update($data);

        return response()->json(['data' => $performanceCycle->fresh()]);
    }

    private function may(string $permission): void
    {
        abort_unless(
            auth()->user()?->hasPermission($permission, app('current.organization_id')),
            403,
        );
    }
}
