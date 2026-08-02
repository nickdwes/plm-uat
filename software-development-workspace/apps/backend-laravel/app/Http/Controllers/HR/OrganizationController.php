<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrganizationController extends Controller
{
    /**
     * List all organisations the authenticated user has access to (full detail).
     */
    public function index(Request $request): JsonResponse
    {
        $user   = $request->user();
        $orgIds = $user->accessibleOrganizationIds();

        $orgs = Organization::whereIn('id', $orgIds)
            ->orderBy('name')
            ->get([
                'id', 'name', 'slug', 'legal_name', 'industry',
                'country', 'currency', 'primary_email', 'primary_phone',
                'address', 'is_active', 'logo_path', 'primary_color',
            ])
            ->map(fn ($org) => array_merge($org->makeHidden('logo_path')->toArray(), [
                'logo_url' => $org->logoUrl(),
            ]));

        return response()->json(['data' => $orgs]);
    }

    /**
     * Get a single organisation by slug or ID (only if user has access).
     */
    public function show(Request $request, string $organization): JsonResponse
    {
        $query = is_numeric($organization)
            ? Organization::where('id', $organization)
            : Organization::where('slug', $organization);

        $organization = $query->firstOrFail();

        $user   = $request->user();
        $orgIds = $user->accessibleOrganizationIds();

        if (! in_array($organization->id, $orgIds, true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'data' => array_merge(
                $organization->only([
                    'id', 'name', 'slug', 'legal_name', 'industry',
                    'country', 'currency', 'primary_email', 'primary_phone',
                    'address', 'is_active', 'primary_color',
                ]),
                [
                    'logo_url'   => $organization->logoUrl(),
                    'headcount'  => $organization->headcount(),
                ]
            ),
        ]);
    }

    /**

    {
        $data = $request->validate([
            'name'          => 'required|string|max:150',
            'legal_name'    => 'nullable|string|max:200',
            'industry'      => 'nullable|string|max:100',
            'country'       => 'nullable|string|size:2',
            'currency'      => 'nullable|string|size:3',
            'primary_email' => 'nullable|email|max:150',
            'primary_phone' => 'nullable|string|max:30',
            'address'       => 'nullable|string|max:300',
        ]);

        // Generate a unique slug from the name
        $baseSlug = Str::slug($data['name']);
        $slug     = $baseSlug;
        $counter  = 1;
        while (Organization::where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        $org = Organization::create(array_merge($data, [
            'slug'      => $slug,
            'currency'  => $data['currency'] ?? 'KES',
            'country'   => $data['country']  ?? 'KE',
            'is_active' => true,
        ]));

        // Assign user as Org HR Admin on this new organisation
        $role = Role::where('name', 'org_hr')->first();
        if ($role) {
            $request->user()->roles()->attach($role->id, [
                'organization_id' => $org->id,
            ]);
        }

        return response()->json([
            'data'    => $org->only(['id', 'name', 'slug', 'legal_name', 'industry', 'country', 'currency', 'primary_email', 'primary_phone', 'address', 'is_active']),
            'message' => 'Organisation registered successfully.',
        ], 201);
    }

    /**
     * Update profile of an organisation the user administers.
     */
    public function update(Request $request, Organization $organization): JsonResponse
    {
        $user = $request->user();

        // Only group admins or org-level HR may update this org
        if (! $user->isGroupAdmin() && ! $user->hasRole('org_hr', $organization->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'name'          => 'sometimes|string|max:150',
            'legal_name'    => 'nullable|string|max:200',
            'industry'      => 'nullable|string|max:100',
            'country'       => 'nullable|string|size:2',
            'currency'      => 'nullable|string|size:3',
            'primary_email' => 'nullable|email|max:150',
            'primary_phone' => 'nullable|string|max:30',
            'address'       => 'nullable|string|max:300',
            'is_active'     => 'nullable|boolean',
            'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $organization->update($data);

        $fresh = $organization->fresh();
        return response()->json([
            'data'    => array_merge(
                $fresh->only(['id', 'name', 'slug', 'legal_name', 'industry', 'country', 'currency', 'primary_email', 'primary_phone', 'address', 'is_active', 'primary_color']),
                ['logo_url' => $fresh->logoUrl()]
            ),
            'message' => 'Organisation updated.',
        ]);
    }

    /**
     * Upload or replace the logo for an organisation.
     */
    public function uploadLogo(Request $request, Organization $organization): JsonResponse
    {
        $user = $request->user();

        if (! $user->isGroupAdmin() && ! $user->hasRole('org_hr', $organization->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->validate([
            'logo' => 'required|image|mimes:png,jpg,jpeg,svg,webp|max:2048',
        ]);

        // Delete old logo if present
        if ($organization->logo_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($organization->logo_path);
        }

        $path = $request->file('logo')->store("logos/{$organization->slug}", 'public');

        $organization->update(['logo_path' => $path]);

        return response()->json([
            'logo_url'      => $organization->logoUrl(),
            'primary_color' => $organization->primary_color,
            'message'       => 'Logo updated successfully.',
        ]);
    }
}
