<?php

namespace App\Models\Account;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AccountContact extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $fillable = [
        'organization_id', 'account_id', 'category', 'title', 'first_name', 'last_name',
        'designation', 'department', 'email', 'phone', 'alternate_phone',
        'is_primary', 'is_active', 'created_by',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active'  => 'boolean',
    ];

    protected $attributes = [
        'category'   => 'other',
        'is_primary' => false,
        'is_active'  => true,
    ];

    /**
     * What kind of contact this is.
     *
     * `primary` is not just another value: it is the one the service keeps
     * unique, and `is_primary` is derived from it so the two cannot disagree
     * about who to call.
     */
    public const CATEGORIES = ['primary', 'secondary', 'technical', 'finance', 'procurement', 'hr', 'other'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim(collect([$this->title, $this->first_name, $this->last_name])->filter()->join(' '));
    }
}
