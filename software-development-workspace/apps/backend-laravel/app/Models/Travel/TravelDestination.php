<?php

namespace App\Models\Travel;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class TravelDestination extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'name', 'country', 'is_international', 'is_active'];

    protected $casts = ['is_international' => 'boolean', 'is_active' => 'boolean'];

    protected $attributes = ['is_international' => false, 'is_active' => true];
}
