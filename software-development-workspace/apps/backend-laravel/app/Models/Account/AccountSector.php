<?php

namespace App\Models\Account;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountSector extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = ['is_active' => true];

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'account_sector_id');
    }
}
