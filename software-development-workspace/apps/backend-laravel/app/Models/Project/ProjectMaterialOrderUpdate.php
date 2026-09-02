<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A delivery update on a material order — the supplier's latest, and the plan. */
class ProjectMaterialOrderUpdate extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_material_order_updates';

    protected $fillable = ['organization_id', 'material_order_id', 'latest_supplier_update', 'delivery_plan_status', 'created_by'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProjectMaterialOrder::class, 'material_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
