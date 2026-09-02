<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A logistics contact moving a material order. */
class ProjectMaterialOrderContact extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_material_order_contacts';

    protected $fillable = ['organization_id', 'material_order_id', 'name', 'email', 'phone', 'address', 'created_by'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProjectMaterialOrder::class, 'material_order_id');
    }
}
