<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Asset\Asset;
use App\Models\Support\Article;
use App\Models\Support\ArticleLink;
use App\Models\Support\Ticket;
use App\Models\Support\TicketAsset;
use App\Services\Support\KnowledgeBase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The knowledge base, and the kit a ticket was about.
 *
 * Two halves of one phase because they answer the same complaint from different
 * directions: the desk explaining the same thing twice, and nobody being able
 * to say what keeps breaking.
 */
class KnowledgeController extends Controller
{
    public function __construct(private readonly KnowledgeBase $kb) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Ticket::class);

        $query = Article::with(['author:id,name', 'category:id,name'])
            ->orderByDesc('updated_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($term = trim((string) $request->string('q'))) {
            $like = '%' . $term . '%';
            $query->where(fn ($q) => $q->where('title', 'ilike', $like)
                ->orWhere('summary', 'ilike', $like));
        }

        return response()->json([
            'data' => $query->limit(100)->get([
                'id', 'title', 'slug', 'summary', 'status', 'visibility',
                'service_category_id', 'author_id', 'published_at',
                'view_count', 'helpful_count', 'unhelpful_count', 'updated_at',
            ])->map(fn (Article $a) => $a->toArray() + ['misleading' => $a->isMisleading()]),

            'counts' => [
                'draft'     => Article::where('status', Article::STATUS_DRAFT)->count(),
                'in_review' => Article::where('status', Article::STATUS_IN_REVIEW)->count(),
                'published' => Article::where('status', Article::STATUS_PUBLISHED)->count(),
            ],
        ]);
    }

    public function show(int $article): JsonResponse
    {
        $this->authorize('viewAny', Ticket::class);

        $record = Article::with(['author:id,name', 'sourceTicket:id,reference,subject'])
            ->findOrFail($article);

        return response()->json(['data' => $record]);
    }

    public function save(Request $request): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        $data = $request->validate([
            'id'                  => ['nullable', 'integer', 'exists:support_articles,id'],
            'title'               => ['required', 'string', 'max:220'],
            'summary'             => ['nullable', 'string', 'max:400'],
            'body'                => ['required', 'string'],
            'visibility'          => ['required', 'in:internal,customer'],
            'service_category_id' => ['nullable', 'integer', 'exists:support_service_categories,id'],
        ]);

        $article = Article::find($data['id'] ?? null);

        if ($article) {
            /*
             * Editing a live article drops it back to review. Advice already
             * being followed must not change under people without somebody
             * looking at it first.
             */
            $wasLive = $article->isLive();

            $article->update(collect($data)->except('id')->all()
                + ($wasLive ? ['status' => Article::STATUS_IN_REVIEW] : []));

            return response()->json([
                'data'    => $article->fresh(),
                'message' => $wasLive
                    ? 'Saved and sent back for review — it was published, and a change to live advice needs a second pair of eyes.'
                    : 'Saved.',
            ]);
        }

        $article = Article::create(collect($data)->except('id')->all() + [
            'organization_id' => app('current.organization_id'),
            'slug'            => \Str::slug($data['title']) . '-' . \Str::random(5),
            'author_id'       => $request->user()?->id,
        ]);

        return response()->json(['data' => $article], 201);
    }

    /**
     * Move an article through draft, review and published.
     *
     * Publishing is gated apart from writing: an article is advice given in the
     * company's name to somebody who cannot judge it, and wrong advice
     * published once is repeated by everybody who finds it.
     */
    public function transition(Request $request, int $article): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:draft,in_review,published,archived'],
        ]);

        if (in_array($data['status'], [Article::STATUS_PUBLISHED, Article::STATUS_ARCHIVED], true)) {
            $this->authorize('manageConfig', Ticket::class);
        } else {
            $this->authorize('update', Ticket::class);
        }

        $record = Article::findOrFail($article);

        $record->forceFill([
            'status'       => $data['status'],
            'published_at' => $data['status'] === Article::STATUS_PUBLISHED
                ? ($record->published_at ?? now()) : $record->published_at,
            'reviewed_by'  => $data['status'] === Article::STATUS_PUBLISHED
                ? $request->user()?->id : $record->reviewed_by,
            'reviewed_at'  => $data['status'] === Article::STATUS_PUBLISHED ? now() : $record->reviewed_at,
        ])->save();

        return response()->json(['data' => $record->fresh()]);
    }

    /** What might already answer this ticket. */
    public function suggest(int $ticket): JsonResponse
    {
        $this->authorize('viewAny', Ticket::class);

        return response()->json(['data' => $this->kb->suggestFor(Ticket::findOrFail($ticket))]);
    }

    /** Attach one to a ticket, so the same answer is not retyped. */
    public function attach(Request $request, int $ticket): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        $data = $request->validate([
            'article_id' => ['required', 'integer', 'exists:support_articles,id'],
        ]);

        $record  = Ticket::findOrFail($ticket);
        $article = Article::findOrFail($data['article_id']);

        $this->kb->record($article, $record, ArticleLink::ATTACHED, $request->user());
        $article->increment('view_count');

        return response()->json(['body' => $article->body, 'title' => $article->title]);
    }

    /** Write up a fix while it is still fresh. */
    public function draftFromTicket(Request $request, int $ticket): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        $data = $request->validate([
            'title'      => ['nullable', 'string', 'max:220'],
            'summary'    => ['nullable', 'string', 'max:400'],
            'body'       => ['nullable', 'string'],
            'visibility' => ['nullable', 'in:internal,customer'],
        ]);

        $article = $this->kb->draftFromTicket(Ticket::findOrFail($ticket), $data, $request->user());

        return response()->json([
            'data'    => $article,
            'message' => 'Drafted from this ticket. It is not published — an article is written for '
                . 'somebody who was not there, so it wants a read first.',
        ], 201);
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    /**
     * Link a ticket to the kit it was about.
     *
     * The old system had the schema for this and nought rows, so for an
     * infrastructure business "what keeps breaking?" has never been answerable.
     */
    public function linkAsset(Request $request, int $ticket): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        $data = $request->validate([
            'asset_id'     => ['required', 'integer', 'exists:assets,id'],
            'relationship' => ['nullable', 'in:primary,affected'],
        ]);

        $record = Ticket::findOrFail($ticket);
        Asset::findOrFail($data['asset_id']);   // scoped: another tenant's asset 404s

        TicketAsset::updateOrCreate(
            ['support_ticket_id' => $record->id, 'asset_id' => $data['asset_id']],
            ['relationship' => $data['relationship'] ?? TicketAsset::AFFECTED,
             'linked_by' => $request->user()?->id],
        );

        return response()->json(['data' => $record->fresh('assets')]);
    }

    public function unlinkAsset(int $ticket, int $asset): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        TicketAsset::where('support_ticket_id', $ticket)->where('asset_id', $asset)->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * What this asset has cost in call-outs.
     *
     * The question the link exists to answer. Open tickets first, then the
     * history, because the useful question about a switch that keeps failing is
     * usually "is it broken right now".
     */
    public function assetHistory(int $asset): JsonResponse
    {
        $this->authorize('viewAny', Ticket::class);

        $record = Asset::findOrFail($asset);

        $tickets = Ticket::whereHas('assets', fn ($q) => $q->where('assets.id', $record->id))
            ->with(['status:id,name,state', 'assignee:id,name'])
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'reference', 'subject', 'ticket_status_id', 'assigned_to', 'opened_at', 'resolved_at']);

        return response()->json([
            'asset'   => $record->only(['id', 'name', 'asset_tag', 'serial_number', 'status']),
            'open'    => $tickets->filter(fn ($t) => in_array($t->status?->state, ['open', 'pending'], true))->values(),
            'history' => $tickets->values(),
            'counts'  => [
                'total'      => $tickets->count(),
                'this_year'  => $tickets->filter(fn ($t) => $t->opened_at?->isCurrentYear())->count(),
            ],
        ]);
    }
}
