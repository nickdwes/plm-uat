<?php

namespace App\Models\Asset;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One asset depending on another.
 *
 * Typed and directional. "Related to" tells nobody whether taking the server
 * down breaks the application, and that is the whole question a dependency map
 * exists to answer.
 */
class AssetRelationship extends Model
{
    use BelongsToOrganization;

    protected $table = 'asset_relationships';

    protected $fillable = [
        'organization_id', 'asset_id', 'related_asset_id',
        'relationship', 'note', 'created_by',
    ];

    public const TYPES = [
        'runs_on', 'hosted_by', 'authenticates_against', 'backed_up_by',
        'secured_by', 'depends_on', 'connects_to', 'replaces',
    ];

    /**
     * How each reads from the other end.
     *
     * So an asset can show both what it depends on and what depends on it,
     * without either side having to store the relationship twice — two rows
     * for one fact is two rows to keep in step.
     */
    public const INVERSE_LABEL = [
        'runs_on'               => 'runs here',
        'hosted_by'             => 'hosts',
        'authenticates_against' => 'authenticates',
        'backed_up_by'          => 'backs up',
        'secured_by'            => 'secures',
        'depends_on'            => 'is depended on by',
        'connects_to'           => 'is connected to by',
        'replaces'              => 'was replaced by',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function related(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'related_asset_id');
    }
}
