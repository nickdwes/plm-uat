<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One checkpoint in a material order's journey. */
class ProjectMaterialOrderTracker extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_material_order_trackers';

    protected $fillable = ['organization_id', 'material_order_id', 'name', 'details', 'happened_on', 'created_by'];

    protected $casts = ['happened_on' => 'date'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProjectMaterialOrder::class, 'material_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
