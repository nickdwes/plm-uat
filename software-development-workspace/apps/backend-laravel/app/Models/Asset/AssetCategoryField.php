<?php

namespace App\Models\Asset;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One thing worth capturing about a particular kind of asset.
 *
 * A UPS wants capacity in VA and a battery replacement date; a vehicle wants a
 * registration and a logbook number. Neither belongs as a column on every
 * asset, and both used to end up in the notes field where nothing can be
 * reported on or expire.
 */
class AssetCategoryField extends Model
{
    use BelongsToOrganization;

    protected $table = 'asset_category_fields';

    protected $fillable = [
        'organization_id', 'asset_category_id', 'key', 'label',
        'type', 'options', 'help', 'is_required', 'is_unique', 'sort_order',
    ];

    protected $casts = [
        'options'     => 'array',
        'is_required' => 'boolean',
        'is_unique'   => 'boolean',
    ];

    /**
     * Deliberately small.
     *
     * Every type here has an obvious control and an obvious way to validate. A
     * longer list — currency, email, file, lookup — each need their own storage
     * and rendering decisions, and guessing at them before anybody has asked
     * produces types nobody uses and a form that cannot be trusted.
     */
    public const TYPES = ['text', 'textarea', 'number', 'date', 'select', 'boolean'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    /**
     * The stable name answers are stored under.
     *
     * Generated once from the label. Never regenerated: the values on every
     * asset are keyed by it, so changing it would orphan them all — which is
     * why the label is editable and this is not.
     */
    public static function keyFor(string $label): string
    {
        return Str::of($label)->slug('_')->limit(60, '')->toString();
    }

    /** Validation for one answer, from the definition. */
    public function rules(): array
    {
        $rules = [$this->is_required ? 'required' : 'nullable'];

        $rules[] = match ($this->type) {
            'number'   => 'numeric',
            'date'     => 'date',
            'boolean'  => 'boolean',
            'select'   => 'string',
            'textarea' => 'string',
            default    => 'string',
        };

        if ($this->type === 'select' && $this->options) {
            $rules[] = 'in:' . implode(',', $this->options);
        }

        if (in_array($this->type, ['text', 'select'], true)) {
            $rules[] = 'max:255';
        }

        if ($this->type === 'textarea') {
            $rules[] = 'max:5000';
        }

        return $rules;
    }
}
