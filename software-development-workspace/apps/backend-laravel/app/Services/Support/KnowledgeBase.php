<?php

namespace App\Services\Support;

use App\Models\Support\Article;
use App\Models\Support\ArticleLink;
use App\Models\Support\Ticket;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Finding the answer somebody already wrote.
 *
 * ── On the search ──
 *
 * Weighted ILIKE across title, summary and body. Postgres full-text would rank
 * better and needs a tsvector column, a GIN index and a locale decision; at the
 * scale a desk's knowledge base actually reaches — tens to low hundreds of
 * articles — this finds the same things, and pretending otherwise would be
 * building for a problem nobody has yet. Worth revisiting past a few hundred.
 *
 * ── On suggestions ──
 *
 * Offered, never applied. An article auto-attached to a ticket is an answer
 * nobody read being sent in the company's name.
 */
class KnowledgeBase
{
    /**
     * Articles matching a phrase.
     *
     * @param  bool $customerOnly restrict to what a customer may be shown
     */
    public function search(int $organizationId, string $phrase, bool $customerOnly = false, int $limit = 8): array
    {
        $terms = collect(preg_split('/\s+/', trim($phrase)))
            ->filter(fn ($t) => mb_strlen($t) >= 3)
            ->take(6);

        if ($terms->isEmpty()) {
            return [];
        }

        $query = Article::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId);

        $customerOnly ? $query->forCustomers() : $query->published();

        /*
         * Every term must appear somewhere. Matching any term turns a three
         * word subject into every article mentioning "the", and a suggestion
         * list nobody trusts is one nobody reads.
         */
        foreach ($terms as $term) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';

            $query->where(fn ($q) => $q->where('title', 'ilike', $like)
                ->orWhere('summary', 'ilike', $like)
                ->orWhere('body', 'ilike', $like));
        }

        $articles = $query->limit(40)->get();

        return $articles
            ->map(fn (Article $a) => ['article' => $a, 'score' => $this->score($a, $terms->all())])
            ->sortByDesc('score')
            ->take($limit)
            ->map(fn ($row) => [
                'id'      => $row['article']->id,
                'slug'    => $row['article']->slug,
                'title'   => $row['article']->title,
                'summary' => $row['article']->summary,
                'score'   => $row['score'],
                'views'   => $row['article']->view_count,
                // Surfaced rather than hidden: an article being followed and
                // marked wrong is worse than none at all.
                'misleading' => $row['article']->isMisleading(),
            ])
            ->values()
            ->all();
    }

    /** What might answer this ticket, without touching it. */
    public function suggestFor(Ticket $ticket, int $limit = 5): array
    {
        return $this->search(
            $ticket->organization_id,
            $ticket->subject . ' ' . Str::limit((string) $ticket->body, 300, ''),
            customerOnly: false,
            limit: $limit,
        );
    }

    /**
     * Write up a fix while it is still fresh.
     *
     * Seeded from the ticket and left as a draft. Publishing straight from a
     * closed ticket would put the working notes of one incident in front of
     * every customer who searches — an article is written for somebody who was
     * not there.
     */
    public function draftFromTicket(Ticket $ticket, array $data, ?User $actor = null): Article
    {
        $title = $data['title'] ?? $ticket->subject;

        return Article::create([
            'organization_id'     => $ticket->organization_id,
            'title'               => $title,
            'slug'                => $this->uniqueSlug($ticket->organization_id, $title),
            'summary'             => $data['summary'] ?? null,
            'body'                => $data['body'] ?? $this->seedBody($ticket),
            'status'              => Article::STATUS_DRAFT,
            'visibility'          => $data['visibility'] ?? Article::INTERNAL,
            'service_category_id' => $ticket->service_category_id,
            'author_id'           => $actor?->id,
            'source_ticket_id'    => $ticket->id,
        ]);
    }

    /** Record that an article was used, and how. */
    public function record(Article $article, ?Ticket $ticket, string $usage, ?User $actor = null): void
    {
        ArticleLink::create([
            'support_article_id' => $article->id,
            'support_ticket_id'  => $ticket?->id,
            'usage'              => $usage,
            'user_id'            => $actor?->id,
        ]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * A title match is worth far more than a body match.
     *
     * Somebody searching "vpn disconnects" wants the article called that, not
     * the one about mail routing that mentions the VPN in passing.
     */
    private function score(Article $article, array $terms): int
    {
        $score = 0;

        foreach ($terms as $term) {
            if (stripos($article->title, $term) !== false)   { $score += 10; }
            if (stripos((string) $article->summary, $term) !== false) { $score += 4; }
            if (stripos($article->body, $term) !== false)    { $score += 1; }
        }

        // A little weight for what people actually open, and a penalty for
        // what they open and then mark wrong.
        $score += min(5, (int) ($article->view_count / 20));
        $score += min(5, $article->helpful_count);
        $score -= min(10, $article->unhelpful_count * 2);

        return $score;
    }

    private function seedBody(Ticket $ticket): string
    {
        $public = $ticket->messages()
            ->where('visibility', 'public')
            ->where('is_from_agent', true)
            ->orderByDesc('id')
            ->first();

        return trim(implode("\n\n", array_filter([
            '## What happened',
            $ticket->body,
            '## What fixed it',
            $public?->body ?? '',
        ])));
    }

    private function uniqueSlug(int $organizationId, string $title): string
    {
        $base = Str::slug($title) ?: 'article';
        $slug = $base;
        $n    = 1;

        while (Article::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$n);
        }

        return $slug;
    }
}
