<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An answer worth keeping.
 *
 * The old system had none of this — no articles, no FAQ, nothing — so every
 * answer was retyped from memory, and the same fault got a different
 * explanation depending on who picked it up.
 */
class Article extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_articles';

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED  = 'archived';

    public const INTERNAL = 'internal';
    public const CUSTOMER = 'customer';

    protected $fillable = [
        'organization_id', 'title', 'slug', 'summary', 'body',
        'status', 'visibility', 'service_category_id',
        'author_id', 'reviewed_by', 'published_at', 'reviewed_at', 'source_ticket_id',
        'view_count', 'helpful_count', 'unhelpful_count', 'last_viewed_at',
    ];

    protected $casts = [
        'published_at'   => 'datetime',
        'reviewed_at'    => 'datetime',
        'last_viewed_at' => 'datetime',
        'view_count'     => 'integer',
        'helpful_count'  => 'integer',
        'unhelpful_count' => 'integer',
    ];

    protected $attributes = [
        'status'     => self::STATUS_DRAFT,
        'visibility' => self::INTERNAL,
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function sourceTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'source_ticket_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(ArticleLink::class, 'support_article_id');
    }

    /** Live advice. Anything else is somebody's working draft. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /** What a customer may be shown, which is a narrower set again. */
    public function scopeForCustomers(Builder $query): Builder
    {
        return $query->published()->where('visibility', self::CUSTOMER);
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Whether this is doing more harm than good.
     *
     * An article nobody opens is merely unfindable. One opened often and
     * marked unhelpful is worse than none at all — it is confidently wrong and
     * being followed.
     */
    public function isMisleading(): bool
    {
        $votes = $this->helpful_count + $this->unhelpful_count;

        return $votes >= 5 && $this->unhelpful_count > $this->helpful_count;
    }
}
