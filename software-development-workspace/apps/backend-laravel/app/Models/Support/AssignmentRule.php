<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a ticket goes, decided by the first rule that matches.
 *
 * Deliberately not a rules engine. Four conditions cover the routing a desk
 * this size actually does, and a rule nobody can read is a rule nobody can
 * debug at eight in the morning.
 */
class AssignmentRule extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_assignment_rules';

    public const MODE_NONE        = 'none';
    public const MODE_ROUND_ROBIN = 'round_robin';
    public const MODE_LEAST_BUSY  = 'least_busy';
    public const MODE_SPECIFIC    = 'specific';

    protected $fillable = [
        'organization_id', 'name', 'position', 'is_active',
        'match_account_id', 'match_type_id', 'match_priority_id', 'match_domain', 'match_keyword',
        'assign_team_id', 'assign_mode', 'assign_user_id',
    ];

    protected $casts = ['is_active' => 'boolean', 'position' => 'integer'];

    protected $attributes = ['is_active' => true, 'assign_mode' => self::MODE_NONE, 'position' => 0];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'assign_team_id');
    }

    /**
     * Does this rule apply?
     *
     * Every condition that is set must match. An unset condition is ignored
     * rather than treated as a wildcard requirement — a rule with only a domain
     * on it should match on the domain and nothing else.
     */
    public function matches(Ticket $ticket): bool
    {
        if ($this->match_account_id && $ticket->account_id !== $this->match_account_id) {
            return false;
        }

        if ($this->match_type_id && $ticket->ticket_type_id !== $this->match_type_id) {
            return false;
        }

        if ($this->match_priority_id && $ticket->priority_id !== $this->match_priority_id) {
            return false;
        }

        if ($this->match_domain) {
            $address = mb_strtolower((string) $ticket->requester_email);
            $domain  = str_contains($address, '@') ? substr(strrchr($address, '@'), 1) : '';

            if ($domain !== mb_strtolower($this->match_domain)) {
                return false;
            }
        }

        if ($this->match_keyword) {
            $haystack = mb_strtolower($ticket->subject . ' ' . (string) $ticket->body);

            if (! str_contains($haystack, mb_strtolower($this->match_keyword))) {
                return false;
            }
        }

        /*
         * A rule with no conditions at all matches everything, which is a
         * legitimate catch-all at the bottom of the list — and dangerous at the
         * top, which is why position is explicit rather than by id.
         */
        return true;
    }
}
