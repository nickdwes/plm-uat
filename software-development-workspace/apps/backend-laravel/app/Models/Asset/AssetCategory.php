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

    protected $fillable = [
        'organization_id', 'asset_class', 'name', 'description',
        'is_active', 'sort_order',
    ];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = ['is_active' => true, 'sort_order' => 0];

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
