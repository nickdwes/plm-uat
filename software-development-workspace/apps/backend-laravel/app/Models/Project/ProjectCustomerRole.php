<?php

namespace App\Models\Project;

use App\Models\Account\AccountContact;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A seat on the customer's side of the project — the client's sponsor, owner,
 * PM, technical, finance or operations contact — pointed at an account contact.
 */
class ProjectCustomerRole extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_customer_roles';

    /** The customer roles, in order. key => label. */
    public const CATALOG = [
        'customer_sponsor'  => 'Customer Sponsor',
        'customer_owner'    => 'Customer Project Owner',
        'customer_pm'       => 'Customer Project Manager',
        'customer_tech_lead'=> 'Customer Technical Lead',
        'customer_finance'  => 'Customer Finance Representative',
        'customer_ops'      => 'Customer Operations Representative',
    ];

    protected $fillable = [
        'organization_id', 'project_id', 'role_key',
        'account_contact_id', 'responsibility', 'position', 'created_by',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(AccountContact::class, 'account_contact_id');
    }
}
