<?php

namespace App\Models\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An article used on a ticket, and how.
 *
 * The distinction earns its place: an article an agent attached to an answer
 * did its job, and one a customer read before raising a ticket anyway did not.
 * Only the second tells you the article needs rewriting.
 */
class ArticleLink extends Model
{
    protected $table = 'support_article_links';

    public const ATTACHED  = 'attached';
    public const SUGGESTED = 'suggested';
    public const DEFLECTED = 'deflected';

    protected $fillable = ['support_article_id', 'support_ticket_id', 'usage', 'user_id'];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'support_article_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'support_ticket_id');
    }
}
