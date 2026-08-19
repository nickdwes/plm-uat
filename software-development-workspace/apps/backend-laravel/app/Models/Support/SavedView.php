<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** A queue somebody works from. Null user means shared with the desk. */
class SavedView extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_saved_views';

    protected $fillable = ['organization_id', 'user_id', 'name', 'filters', 'position'];

    protected $casts = ['filters' => 'array', 'position' => 'integer'];

    public function isShared(): bool
    {
        return $this->user_id === null;
    }
}
