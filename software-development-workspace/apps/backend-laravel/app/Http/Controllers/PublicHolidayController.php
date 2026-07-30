<?php

namespace App\Http\Controllers;

use App\Support\Authorization\Permission;

use App\Models\PublicHoliday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PublicHolidayController extends Controller
{
    private const NAGER_BASE = 'https://date.nager.at/api/v3/PublicHolidays';

    // ── GET /api/v1/public-holidays?year=2026 ─────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::ORGANIZATIONS_VIEW), 403);

        $year  = $request->integer('year', now()->year);
        $orgId = app('current.organization_id');

        $holidays = PublicHoliday::where('year', $year)
            ->orderBy('date')
            ->get();

        return response()->json(['data' => $holidays, 'year' => $year]);
    }

    // ── POST /api/v1/public-holidays ──────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::ORGANIZATIONS_MANAGE), 403);

        $data = $request->validate([
            'name'         => 'required|string|max:150',
            'local_name'   => 'nullable|string|max:150',
            'date'         => 'required|date',
            'country_code' => 'nullable|string|size:2',
            'is_active'    => 'boolean',
        ]);

        $data['organization_id'] = app('current.organization_id');
        $data['year']            = (int) date('Y', strtotime($data['date']));
        $data['country_code']    = strtoupper($data['country_code'] ?? 'KE');
        $data['source']          = 'manual';

        $holiday = PublicHoliday::create($data);

        return response()->json(['data' => $holiday], 201);
    }

    // ── PUT /api/v1/public-holidays/{holiday} ─────────────────────────────────

    public function update(Request $request, PublicHoliday $publicHoliday): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::ORGANIZATIONS_MANAGE), 403);

        $data = $request->validate([
            'name'       => 'sometimes|string|max:150',
            'local_name' => 'nullable|string|max:150',
            'date'       => 'sometimes|date',
            'is_active'  => 'boolean',
        ]);

        if (isset($data['date'])) {
            $data['year'] = (int) date('Y', strtotime($data['date']));
        }

        $publicHoliday->update($data);

        return response()->json(['data' => $publicHoliday]);
    }

    // ── DELETE /api/v1/public-holidays/{holiday} ──────────────────────────────

    public function destroy(PublicHoliday $publicHoliday): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::ORGANIZATIONS_MANAGE), 403);

        $publicHoliday->delete();

        return response()->json(null, 204);
    }

    // ── POST /api/v1/public-holidays/import ───────────────────────────────────
    // Fetches from date.nager.at and upserts into the org's holidays for the year.

    public function import(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::ORGANIZATIONS_MANAGE), 403);

        $request->validate([
            'year'         => 'required|integer|min:2020|max:2100',
            'country_code' => 'nullable|string|size:2',
        ]);

        $year    = $request->integer('year');
        $country = strtoupper($request->input('country_code', 'KE'));
        $orgId   = app('current.organization_id');

        try {
            $response = Http::timeout(10)->get(self::NAGER_BASE . "/{$year}/{$country}");
        } catch (\Throwable $e) {
            Log::error('PublicHoliday import failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to reach holiday data source. Check your internet connection.'], 503);
        }

        if (! $response->successful()) {
            return response()->json([
                'message' => "No holiday data available for country '{$country}' in {$year}.",
            ], 422);
        }

        $items   = $response->json();
        $created = 0;
        $skipped = 0;

        foreach ($items as $item) {
            $exists = PublicHoliday::withoutGlobalScope('organization')
                ->where('organization_id', $orgId)
                ->where('date', $item['date'])
                ->where('name', $item['name'])
                ->withTrashed()
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            PublicHoliday::create([
                'organization_id' => $orgId,
                'name'            => $item['name'],
                'local_name'      => $item['localName'] !== $item['name'] ? $item['localName'] : null,
                'date'            => $item['date'],
                'year'            => $year,
                'country_code'    => $country,
                'source'          => 'imported',
                'is_active'       => true,
            ]);

            $created++;
        }

        return response()->json([
            'message' => "Imported {$created} holidays for {$country} {$year}. {$skipped} already existed.",
            'created' => $created,
            'skipped' => $skipped,
            'year'    => $year,
        ]);
    }
}
