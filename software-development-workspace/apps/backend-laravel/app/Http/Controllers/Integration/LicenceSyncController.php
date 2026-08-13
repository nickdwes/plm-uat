<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Models\Integration\IntegrationConnection;
use App\Services\Integration\LicenceSnapshot;
use App\Services\Integration\LicenceStatistics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Pulling the licence picture in, and reporting it.
 *
 * The snapshot is not the asset register. Nothing here creates assets — that
 * comes next, and it needs decisions this does not: which class a cloud
 * resource belongs to, and whether a seat allocation may be written against a
 * person nobody has matched yet.
 *
 * What this does give is every licence number that needs no employee record to
 * be true, which is most of them.
 */
class LicenceSyncController extends Controller
{
    public function __construct(
        private readonly LicenceSnapshot $snapshot,
        private readonly LicenceStatistics $statistics,
    ) {}

    /** Read the tenant and write the snapshot. */
    public function sync(IntegrationConnection $connection): JsonResponse
    {
        $this->authorize('sync', $connection);

        abort_unless(
            $connection->is_active,
            422,
            'That connection is paused. Switch it on before syncing.',
        );

        abort_unless(
            $connection->sync_licences,
            422,
            'That connection is not set to read Microsoft 365 licences.',
        );

        try {
            $result = $this->snapshot->pull($connection);
        } catch (Throwable $e) {
            $connection->update([
                'last_test_status'  => IntegrationConnection::STATUS_FAILED,
                'last_test_message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'result'     => $result,
            'statistics' => $this->statistics->for($connection->id),
            'synced_at'  => $connection->fresh()->last_synced_at?->toDateTimeString(),
        ]);
    }

    /**
     * The counts, from what was last pulled.
     *
     * Served from the snapshot rather than from Microsoft, so opening the page
     * does not depend on the tenant being reachable and does not put a
     * directory read behind every refresh.
     */
    public function statistics(Request $request): JsonResponse
    {
        $this->authorize('viewAny', IntegrationConnection::class);

        $connectionId = $request->integer('connection_id') ?: null;

        return response()->json([
            'statistics'  => $this->statistics->for($connectionId),
            'connections' => IntegrationConnection::orderBy('name')
                ->get(['id', 'name', 'last_synced_at', 'sync_licences', 'is_active'])
                ->map(fn (IntegrationConnection $c) => [
                    'id'             => $c->id,
                    'name'           => $c->name,
                    'sync_licences'  => $c->sync_licences,
                    'is_active'      => $c->is_active,
                    'last_synced_at' => $c->last_synced_at?->toDateTimeString(),
                ]),
        ]);
    }
}
