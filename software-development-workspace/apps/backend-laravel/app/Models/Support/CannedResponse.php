<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An answer somebody has already written well.
 *
 * The use count is not vanity: a canned response nobody picks is one that reads
 * badly, and the list sorts by it so the good ones rise without anybody having
 * to curate them.
 */
class CannedResponse extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_canned_responses';

    protected $fillable = [
        'organization_id', 'title', 'body', 'support_team_id',
        'use_count', 'last_used_at', 'created_by',
    ];

    protected $casts = ['use_count' => 'integer', 'last_used_at' => 'datetime'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'support_team_id');
    }

    /**
     * Fill in what the system already knows.
     *
     * A short list on purpose. Every placeholder is a thing that can be blank
     * on a real ticket, and "Hello ," is worse than a sentence somebody typed.
     */
    public function render(Ticket $ticket): string
    {
        return strtr($this->body, [
            '{{reference}}' => $ticket->reference,
            '{{requester}}' => $ticket->requester_name ?: 'there',
            '{{subject}}'   => $ticket->subject,
            '{{assignee}}'  => $ticket->assignee?->name ?? 'the team',
        ]);
    }
}
