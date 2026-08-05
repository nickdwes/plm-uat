<?php

namespace App\Models\Account;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountSubsector extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'account_sector_id', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = ['is_active' => true];

    public function sector(): BelongsTo
    {
        return $this->belongsTo(AccountSector::class, 'account_sector_id');
    }
}
