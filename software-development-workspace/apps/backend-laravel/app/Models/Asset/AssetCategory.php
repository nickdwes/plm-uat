<?php

namespace App\Models\Asset;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind of thing, within a class of thing.
 *
 * Configured per organisation because the four tenants own different
 * equipment, and tied to a class because a category only means anything inside
 * one — "SSL certificate" is not a kind of laptop.
 *
 * Switched off rather than deleted. Assets are filed against a category by
 * name, so removing one would leave rows pointing at something that no longer
 * means anything.
 */
class AssetCategory extends Model
{
    use BelongsToOrganization;

    protected $table = 'asset_categories';

    /**
     * assignee / custodian / none — see the migration for why this lives on the
     * category rather than the class.
     */
    public const CUSTODY_ASSIGNEE  = 'assignee';
    public const CUSTODY_CUSTODIAN = 'custodian';
    public const CUSTODY_NONE      = 'none';

    public const CUSTODY_MODELS = [
        self::CUSTODY_ASSIGNEE,
        self::CUSTODY_CUSTODIAN,
        self::CUSTODY_NONE,
    ];

    protected $fillable = [
        'organization_id', 'asset_class', 'name', 'description',
        'custody_model', 'is_active', 'sort_order',
    ];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = [
        'is_active'     => true,
        'sort_order'    => 0,
        'custody_model' => self::CUSTODY_ASSIGNEE,
    ];

    /** Looked after by somebody, rather than carried by them. */
    public function usesCustodian(): bool
    {
        return $this->custody_model === self::CUSTODY_CUSTODIAN;
    }

    /** Handed to somebody who signs for it and gives it back. */
    public function usesAssignee(): bool
    {
        return $this->custody_model === self::CUSTODY_ASSIGNEE;
    }

    /** What this kind of thing is worth capturing beyond the common fields. */
    public function fields()
    {
        return $this->hasMany(AssetCategoryField::class, 'asset_category_id');
    }

    /** What a picker should offer. */
    public function scopeUsable(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    /**
     * The catch-all, which behaves differently: choosing it asks the person to
     * say what the thing actually is.
     */
    public function isCatchAll(): bool
    {
        return strtolower($this->name) === 'other';
    }
}
