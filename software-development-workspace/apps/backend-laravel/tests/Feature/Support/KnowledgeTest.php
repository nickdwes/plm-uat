<?php

namespace Tests\Feature\Support;

use App\Models\Asset\Asset;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Support\Article;
use App\Models\Support\PortalToken;
use App\Models\Support\Ticket;
use App\Models\User;
use App\Services\Support\KnowledgeBase;
use App\Services\Support\SupportDefaults;
use App\Services\Support\TicketService;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Answers worth keeping, and the kit they were about.
 *
 * The old system had no knowledge base at all, and its asset-to-ticket link had
 * a schema and nought rows. Most of what follows is about the two ways this
 * goes wrong: internal advice reaching a customer, and an article being
 * published without anybody reading it.
 */
class KnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        app(SupportDefaults::class)->ensureFor($this->org->id);
    }

    private function kb(): KnowledgeBase { return app(KnowledgeBase::class); }
    private function service(): TicketService { return app(TicketService::class); }

    private function article(array $attrs = []): Article
    {
        return Article::create(array_merge([
            'organization_id' => $this->org->id,
            'title'   => 'VPN disconnects every few minutes',
            'slug'    => 'vpn-disconnects-' . fake()->unique()->numberBetween(1, 9999),
            'summary' => 'What to check when the VPN drops.',
            'body'    => 'Check the MTU, then the idle timeout on the firewall.',
            'status'  => Article::STATUS_PUBLISHED,
            'visibility' => Article::INTERNAL,
        ], $attrs));
    }

    private function ticket(array $data = []): Ticket
    {
        return $this->service()->open($this->org->id, array_merge([
            'subject' => 'VPN disconnects', 'body' => 'Drops every few minutes.',
            'requester_email' => 'ann@client.co.ke',
        ], $data));
    }

    private function signIn(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Desk', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    // ── Finding things ────────────────────────────────────────────────────────

    public function test_search_finds_an_article_by_its_title(): void
    {
        $this->article();
        $this->article(['title' => 'Mail routing', 'body' => 'Unrelated.']);

        $results = $this->kb()->search($this->org->id, 'vpn disconnects');

        $this->assertCount(1, $results);
        $this->assertStringContainsString('VPN', $results[0]['title']);
    }

    /**
     * Every term must appear. Matching any turns a three-word subject into
     * every article mentioning "the", and a suggestion list nobody trusts is
     * one nobody reads.
     */
    public function test_every_term_must_match(): void
    {
        $this->article(['title' => 'VPN setup', 'body' => 'How to configure the client.']);

        $this->assertCount(0, $this->kb()->search($this->org->id, 'vpn printer'));
        $this->assertCount(1, $this->kb()->search($this->org->id, 'vpn setup'));
    }

    /** A title match beats a passing mention in a body. */
    public function test_a_title_match_ranks_above_a_body_mention(): void
    {
        $this->article(['title' => 'Mail routing', 'body' => 'Note the VPN may also disconnect.']);
        $wanted = $this->article(['title' => 'VPN disconnects constantly']);

        $results = $this->kb()->search($this->org->id, 'vpn disconnects');

        $this->assertSame($wanted->title, $results[0]['title']);
    }

    /** Drafts are somebody's working notes, not advice. */
    public function test_drafts_are_not_searchable(): void
    {
        $this->article(['status' => Article::STATUS_DRAFT]);

        $this->assertCount(0, $this->kb()->search($this->org->id, 'vpn disconnects'));
    }

    // ── What a customer may see ───────────────────────────────────────────────

    /**
     * The distinction the portal depends on. An article naming a supplier and a
     * margin has a place; that place is not a customer's screen.
     */
    public function test_internal_articles_are_invisible_to_customers(): void
    {
        $this->article(['title' => 'VPN disconnects — supplier margin notes']);

        $this->assertCount(1, $this->kb()->search($this->org->id, 'vpn disconnects'));
        $this->assertCount(0, $this->kb()->search($this->org->id, 'vpn disconnects', customerOnly: true));
    }

    public function test_the_portal_only_returns_customer_articles(): void
    {
        $this->article(['title' => 'VPN internal runbook']);
        $this->article(['title' => 'VPN troubleshooting for customers', 'visibility' => Article::CUSTOMER]);

        $token = PortalToken::issue([
            'organization_id' => $this->org->id,
            'account_contact_id' => null,
            'support_ticket_id' => $this->ticket()->id,
            'email' => 'ann@client.co.ke',
        ])['token'];

        $this->getJson('/api/v1/support/portal/help?token=' . $token . '&q=vpn')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertDontSee('runbook', escape: false);
    }

    /** An internal article is not reachable by guessing its slug either. */
    public function test_an_internal_article_cannot_be_opened_from_the_portal(): void
    {
        $internal = $this->article(['visibility' => Article::INTERNAL]);

        $token = PortalToken::issue([
            'organization_id' => $this->org->id,
            'support_ticket_id' => $this->ticket()->id,
            'email' => 'ann@client.co.ke',
        ])['token'];

        $this->getJson("/api/v1/support/portal/article?token={$token}&slug={$internal->slug}")
            ->assertNotFound();
    }

    // ── Publishing ────────────────────────────────────────────────────────────

    /** Writing and publishing are different jobs. */
    public function test_publishing_needs_more_than_permission_to_write(): void
    {
        $article = $this->article(['status' => Article::STATUS_IN_REVIEW]);
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_UPDATE]);

        $this->postJson("/api/v1/support/articles/{$article->id}/status",
            ['status' => 'published'], ['X-Organization' => 'alpha'])->assertForbidden();

        // The same person may still move their own draft into review.
        $this->postJson("/api/v1/support/articles/{$article->id}/status",
            ['status' => 'draft'], ['X-Organization' => 'alpha'])->assertOk();
    }

    /**
     * Advice already being followed must not change under people without
     * somebody looking at it.
     */
    public function test_editing_a_published_article_sends_it_back_for_review(): void
    {
        $article = $this->article();
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_UPDATE]);

        $this->postJson('/api/v1/support/articles', [
            'id' => $article->id, 'title' => $article->title,
            'body' => 'Different advice entirely.', 'visibility' => 'internal',
        ], ['X-Organization' => 'alpha'])->assertOk();

        $this->assertSame(Article::STATUS_IN_REVIEW, $article->fresh()->status);
    }

    // ── Writing one up ────────────────────────────────────────────────────────

    /** An article is written for somebody who was not there. */
    public function test_an_article_drafted_from_a_ticket_is_not_published(): void
    {
        $ticket = $this->ticket();
        $agent  = User::factory()->create();
        $this->service()->reply($ticket, ['body' => 'Lowered the MTU to 1400.'], $agent);

        $article = $this->kb()->draftFromTicket($ticket->fresh(), [], $agent);

        $this->assertSame(Article::STATUS_DRAFT, $article->status);
        $this->assertSame($ticket->id, $article->source_ticket_id);
        // Seeded from what actually happened, so the writer starts from the fix.
        $this->assertStringContainsString('MTU', $article->body);
    }

    /** An article opened often and marked wrong is worse than none. */
    public function test_an_article_people_mark_unhelpful_is_flagged(): void
    {
        $article = $this->article();

        $article->forceFill(['helpful_count' => 1, 'unhelpful_count' => 6])->save();

        $this->assertTrue($article->fresh()->isMisleading());
        $this->assertTrue($this->kb()->search($this->org->id, 'vpn disconnects')[0]['misleading']);
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    private function asset(): Asset
    {
        return Asset::create([
            'organization_id' => $this->org->id,
            'name' => 'Core switch', 'asset_tag' => 'SW-' . fake()->unique()->numberBetween(1, 9999),
            // category is not null on assets; the fixture has to say what it is.
            'category' => 'Network', 'asset_class' => 'hardware', 'status' => 'in_use',
        ]);
    }

    /**
     * The link the old system had a schema for and never used, which is why
     * "what keeps breaking?" has never been answerable.
     */
    public function test_a_ticket_links_to_the_kit_it_was_about(): void
    {
        $ticket = $this->ticket();
        $asset  = $this->asset();
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_UPDATE]);

        $this->postJson("/api/v1/support/tickets/{$ticket->id}/assets",
            ['asset_id' => $asset->id, 'relationship' => 'primary'],
            ['X-Organization' => 'alpha'])->assertOk();

        $this->assertSame(1, $ticket->fresh()->assets()->count());
    }

    /** One fault takes out several things; picking one loses the rest. */
    public function test_a_ticket_can_name_several_assets(): void
    {
        $ticket = $this->ticket();
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_UPDATE]);

        foreach ([$this->asset(), $this->asset(), $this->asset()] as $asset) {
            $this->postJson("/api/v1/support/tickets/{$ticket->id}/assets",
                ['asset_id' => $asset->id], ['X-Organization' => 'alpha'])->assertOk();
        }

        $this->assertSame(3, $ticket->fresh()->assets()->count());
    }

    public function test_an_assets_call_out_history_is_answerable(): void
    {
        $asset = $this->asset();
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_UPDATE]);

        foreach (range(1, 3) as $i) {
            $ticket = $this->ticket(['subject' => "Fault {$i}"]);
            $this->postJson("/api/v1/support/tickets/{$ticket->id}/assets",
                ['asset_id' => $asset->id], ['X-Organization' => 'alpha']);
        }

        $this->getJson("/api/v1/support/assets/{$asset->id}/tickets", ['X-Organization' => 'alpha'])
            ->assertOk()
            ->assertJsonPath('counts.total', 3)
            ->assertJsonPath('asset.name', 'Core switch');
    }

    /** Linking the same asset twice is one link, not two. */
    public function test_linking_the_same_asset_twice_is_idempotent(): void
    {
        $ticket = $this->ticket();
        $asset  = $this->asset();
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_UPDATE]);

        foreach ([1, 2] as $_) {
            $this->postJson("/api/v1/support/tickets/{$ticket->id}/assets",
                ['asset_id' => $asset->id], ['X-Organization' => 'alpha'])->assertOk();
        }

        $this->assertSame(1, $ticket->fresh()->assets()->count());
    }
}
