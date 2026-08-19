<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\OrgPractice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an engineer can take on.
 *
 * Skills are free-form tags rather than a certification matrix with levels and
 * expiry dates. A matrix is only more accurate while somebody maintains it, and
 * a stale one is worse than tags nobody claimed were authoritative — it is
 * consulted with confidence and it is wrong.
 */
class Engineer extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_engineers';

    protected $fillable = [
        'organization_id', 'user_id', 'org_practice_id',
        'skills', 'accepts_assignment', 'is_active',
    ];

    protected $casts = [
        'skills'             => 'array',
        'accepts_assignment' => 'boolean',
        'is_active'          => 'boolean',
    ];

    protected $attributes = ['accepts_assignment' => true, 'is_active' => true];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function practice(): BelongsTo
    {
        return $this->belongsTo(OrgPractice::class, 'org_practice_id');
    }

    public function available(): bool
    {
        return $this->is_active && $this->accepts_assignment;
    }
}
