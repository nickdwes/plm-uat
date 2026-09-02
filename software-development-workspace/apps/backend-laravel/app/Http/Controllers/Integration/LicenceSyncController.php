<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Models\Integration\IntegrationConnection;
use App\Services\Integration\AzureResourceImport;
use App\Services\Integration\LicenceAssetImport;
use App\Services\Integration\LicenceOwnerMapper;
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
        private readonly AzureResourceImport $azure,
        private readonly LicenceAssetImport $licenceAssets,
        private readonly LicenceOwnerMapper $owners,
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
     * What a licence import would put in the register, without doing it.
     *
     * Separate from the licence *sync*, which only refreshes the snapshot the
     * statistics are counted from. This one creates assets, so it is previewed
     * first like the Azure import.
     */
    public function previewLicenceAssets(IntegrationConnection $connection): JsonResponse
    {
        $this->authorize('sync', $connection);

        try {
            return response()->json(['preview' => $this->licenceAssets->preview($connection)]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Create the software products and their subscription agreements. */
    public function importLicenceAssets(IntegrationConnection $connection): JsonResponse
    {
        $this->authorize('sync', $connection);

        abort_unless($connection->is_active, 422, 'That connection is paused.');

        try {
            return response()->json(['result' => $this->licenceAssets->import($connection)]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Put a name to every licence seat.
     *
     * Runs after the import: it matches the directory accounts the sync
     * already writes to employees here, then allocates a seat on each licence
     * asset for every SKU that person holds. Seats nobody here matches are
     * counted and left unallocated, so the gap between Microsoft\'s consumed
     * figure and ours stays visible rather than being papered over.
     */
    public function mapLicenceOwners(Request $request, IntegrationConnection $connection): JsonResponse
    {
        $this->authorize('sync', $connection);

        // A dry run first is the norm here: it says how many seats will land
        // on whom before anything is written to the register.
        $commit = ! $request->boolean('preview');

        abort_unless(! $commit || $connection->is_active, 422, 'That connection is paused.');

        try {
            return response()->json([
                'result'  => $this->owners->run($connection, $commit),
                'preview' => ! $commit,
            ]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * What an Azure import would do, without doing it.
     *
     * Always offered before the commit, because this one writes into the asset
     * register itself rather than into a snapshot of its own — and the first
     * time somebody runs it they should see thirteen machines and thirty-six
     * skipped bits of plumbing before anything appears in the list.
     */
    public function previewAzure(IntegrationConnection $connection): JsonResponse
    {
        $this->authorize('sync', $connection);

        try {
            return response()->json(['preview' => $this->azure->preview($connection)]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Create and update the cloud assets. */
    public function importAzure(IntegrationConnection $connection): JsonResponse
    {
        $this->authorize('sync', $connection);

        abort_unless(
            $connection->is_active,
            422,
            'That connection is paused. Switch it on before importing.',
        );

        try {
            $result = $this->azure->import($connection);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $connection->update(['last_synced_at' => now()]);

        return response()->json(['result' => $result]);
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
