<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One row of the old system, and what it became here.
 *
 * @see database/migrations/2026_08_22_090000_plm_import_map.php
 */
class PlmImportMap extends Model
{
    protected $table = 'plm_import_map';

    protected $fillable = [
        'organization_id', 'source_table', 'source_id',
        'target_type', 'target_id', 'source_snapshot', 'note',
    ];

    protected $casts = ['source_snapshot' => 'array'];

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /** What a legacy row became, or null if it has not been imported. */
    public static function resolve(int $organizationId, string $table, int|string|null $sourceId): ?self
    {
        if ($sourceId === null || $sourceId === '') {
            return null;
        }

        return static::where('organization_id', $organizationId)
            ->where('source_table', $table)
            ->where('source_id', (int) $sourceId)
            ->first();
    }
}
