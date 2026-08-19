<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may open a ticket by writing in.
 *
 * The old module had this table, loaded it, and never checked the result — so
 * any sender on earth could open a ticket and get an auto-reply carrying the
 * company's name. The table was empty too, which is why fixing only the check
 * would have refused every genuine customer.
 */
class SenderRule extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_sender_rules';

    public const KIND_DOMAIN  = 'domain';
    public const KIND_ADDRESS = 'address';

    public const ACTION_ALLOW = 'allow';
    public const ACTION_BLOCK = 'block';

    protected $fillable = ['organization_id', 'kind', 'value', 'action', 'note', 'created_by'];

    protected $attributes = ['action' => self::ACTION_ALLOW];

    /** Stored lowercase so a rule cannot be defeated by capitalisation. */
    public function setValueAttribute(string $value): void
    {
        $this->attributes['value'] = mb_strtolower(trim($value));
    }
}
