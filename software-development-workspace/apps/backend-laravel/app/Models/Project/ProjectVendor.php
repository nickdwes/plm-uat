<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Training\VendorPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supplier engaged on a project. Where the supplier is a known partner it
 * links the vendor_partners register; otherwise it just carries a name.
 */
class ProjectVendor extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_vendors';

    protected $fillable = [
        'organization_id', 'project_id',
        'vendor_partner_id', 'name', 'note',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(VendorPartner::class, 'vendor_partner_id');
    }
}
