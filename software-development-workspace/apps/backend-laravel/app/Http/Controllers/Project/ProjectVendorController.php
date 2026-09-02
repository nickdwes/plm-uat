<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectVendor;
use App\Models\Training\VendorPartner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectVendorController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'data'     => $project->vendors()->with('partner:id,name')->get()
                ->map(fn (ProjectVendor $v) => $this->payload($v)),
            // Known partners the picker can offer, so a supplier links rather
            // than being re-typed each time.
            'partners' => VendorPartner::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'vendor_partner_id' => ['nullable', 'integer', Rule::exists('vendor_partners', 'id')],
            'name'              => ['required_without:vendor_partner_id', 'nullable', 'string', 'max:200'],
            'note'              => ['nullable', 'string', 'max:500'],
        ]);

        // When a partner is chosen but no name typed, inherit the partner's name.
        if (empty($data['name']) && ! empty($data['vendor_partner_id'])) {
            $data['name'] = VendorPartner::whereKey($data['vendor_partner_id'])->value('name') ?? 'Vendor';
        }

        $vendor = $project->vendors()->create($data + [
            'organization_id' => $project->organization_id,
        ]);

        return response()->json(['data' => $this->payload($vendor->fresh('partner:id,name'))], 201);
    }

    public function destroy(Project $project, ProjectVendor $vendor): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($vendor->project_id === $project->id, 404);

        $vendor->delete();

        return response()->json(['message' => 'Vendor removed.']);
    }

    private function payload(ProjectVendor $v): array
    {
        return [
            'id'      => $v->id,
            'name'    => $v->name,
            'note'    => $v->note,
            'partner' => $v->relationLoaded('partner') && $v->partner
                ? ['id' => $v->partner->id, 'name' => $v->partner->name] : null,
        ];
    }
}
