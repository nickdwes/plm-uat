<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Http\Requests\Training\CreateCompetencyRequest;
use App\Http\Requests\Training\CreateRoleProfileRequest;
use App\Http\Resources\Training\CompetencyItemResource;
use App\Http\Resources\Training\RoleProfileResource;
use App\Models\Training\CompetencyItem;
use App\Models\Training\RoleCompetencyProfile;
use App\Services\Training\CompetencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CompetencyController extends Controller
{
    public function __construct(private readonly CompetencyService $service) {}

    // ── Competency Items ───────────────────────────────────────────────────────

    public function indexCompetencies(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CompetencyItem::class);

        $items = $this->service->listCompetencies(
            organizationId: app('current.organization_id'),
            filters: $request->only(['category', 'search']),
            perPage: (int) $request->input('per_page', 50),
        );

        return CompetencyItemResource::collection($items);
    }

    public function storeCompetency(CreateCompetencyRequest $request): CompetencyItemResource
    {
        $item = $this->service->createCompetency(
            organizationId: app('current.organization_id'),
            data: $request->validated(),
        );

        return new CompetencyItemResource($item);
    }

    public function showCompetency(CompetencyItem $competencyItem): CompetencyItemResource
    {
        $this->authorize('view', $competencyItem);
        return new CompetencyItemResource($competencyItem);
    }

    public function updateCompetency(CreateCompetencyRequest $request, CompetencyItem $competencyItem): CompetencyItemResource
    {
        $this->authorize('update', $competencyItem);
        $updated = $this->service->updateCompetency($competencyItem, $request->validated());
        return new CompetencyItemResource($updated);
    }

    public function destroyCompetency(CompetencyItem $competencyItem): JsonResponse
    {
        $this->authorize('delete', $competencyItem);
        $this->service->destroyCompetency($competencyItem);
        return response()->json(null, 204);
    }

    // ── Role Profiles ──────────────────────────────────────────────────────────

    public function indexProfiles(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', RoleCompetencyProfile::class);

        $profiles = $this->service->listProfiles(
            organizationId: app('current.organization_id'),
            filters: $request->only(['department_id', 'search']),
            perPage: (int) $request->input('per_page', 20),
        );

        return RoleProfileResource::collection($profiles);
    }

    public function storeProfile(CreateRoleProfileRequest $request): RoleProfileResource
    {
        $profile = $this->service->createProfile(
            organizationId: app('current.organization_id'),
            data: $request->safe()->except(['competencies']),
            competencies: $request->input('competencies', []),
        );

        return new RoleProfileResource($profile);
    }

    public function showProfile(RoleCompetencyProfile $roleProfile): RoleProfileResource
    {
        $this->authorize('view', $roleProfile);
        return new RoleProfileResource($roleProfile->load(['department', 'competencies']));
    }

    public function updateProfile(CreateRoleProfileRequest $request, RoleCompetencyProfile $roleProfile): RoleProfileResource
    {
        $this->authorize('update', $roleProfile);
        $updated = $this->service->updateProfile(
            profile: $roleProfile,
            data: $request->safe()->except(['competencies']),
            competencies: $request->has('competencies') ? $request->input('competencies') : null,
        );
        return new RoleProfileResource($updated);
    }

    public function destroyProfile(RoleCompetencyProfile $roleProfile): JsonResponse
    {
        $this->authorize('delete', $roleProfile);
        $this->service->destroyProfile($roleProfile);
        return response()->json(null, 204);
    }
}
