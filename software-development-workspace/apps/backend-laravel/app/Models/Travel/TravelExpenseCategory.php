<?php

namespace App\Models\Travel;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class TravelExpenseCategory extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'name', 'code', 'requires_receipt', 'is_active'];

    protected $casts = ['requires_receipt' => 'boolean', 'is_active' => 'boolean'];

    protected $attributes = ['requires_receipt' => false, 'is_active' => true];
}
