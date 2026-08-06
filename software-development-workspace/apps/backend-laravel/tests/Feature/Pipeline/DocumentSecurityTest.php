<?php

namespace Tests\Feature\Pipeline;

use App\Models\Account\Account;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Pipeline\Opportunity;
use App\Models\Role;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use App\Support\Storage\DocumentVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Encryption at rest, and who may see the money.
 *
 * Two guarantees that are invisible from a working screen and expensive when
 * wrong. Blob storage encrypts with a key Microsoft holds, which protects a
 * stolen disk and nothing else — not a leaked storage key, a misconfigured
 * container, or a backup restored somewhere it should not be. In all of those
 * the bytes are readable, and they are tender responses, signed contracts and
 * bid bonds.
 */
class DocumentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('opportunity_documents');
        Storage::fake('account_documents');

        // Set for the test run; in production it lives in Key Vault.
        config(['documents.encryption_key' => 'base64:' . base64_encode(str_repeat('k', 32))]);

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        DefaultReferenceData::seedFor($this->org->id);

        $this->account = Account::create([
            'organization_id' => $this->org->id, 'name' => 'Kenya Commercial Bank',
        ]);
    }

    private function actor(array $permissions): Employee
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        $employee = Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Test', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function deal(array $attrs = []): Opportunity
    {
        return Opportunity::create($attrs + [
            'organization_id' => $this->org->id,
            'account_id'      => $this->account->id,
            'name'            => 'Integrate AI lead scoring module',
        ]);
    }

    private function url(Opportunity $o, string $path = ''): string
    {
        return "/api/v1/opportunities/{$o->id}{$path}?org={$this->org->slug}";
    }

    // ── Encryption at rest ────────────────────────────────────────────────

    public function test_what_reaches_storage_is_not_the_document(): void
    {
        // The whole point: a leaked storage key yields ciphertext.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal();

        $this->post($this->url($deal, '/documents'), [
            'stage' => 'bid',
            'file'  => UploadedFile::fake()->createWithContent('tender.pdf', 'BID VALUE 4,200,000'),
        ])->assertOk();

        $path = $deal->documents()->first()->storage_path;
        $raw  = Storage::disk('opportunity_documents')->get($path);

        $this->assertStringNotContainsString('BID VALUE', $raw);
        $this->assertStringNotContainsString('4,200,000', $raw);
        $this->assertTrue(DocumentVault::isSealed($raw));
    }

    public function test_an_authorised_download_returns_the_original_bytes(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal();

        $this->post($this->url($deal, '/documents'), [
            'stage' => 'bid',
            'file'  => UploadedFile::fake()->createWithContent('tender.pdf', 'BID VALUE 4,200,000'),
        ])->assertOk();

        $id = $deal->documents()->first()->id;

        $res = $this->get($this->url($deal, "/documents/{$id}/download"))->assertOk();

        $this->assertSame('BID VALUE 4,200,000', $res->streamedContent());
    }

    public function test_the_stored_path_does_not_reveal_the_filename(): void
    {
        // A predictable path is half of an access-control failure even behind
        // an authorising route.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal();

        $this->post($this->url($deal, '/documents'), [
            'stage' => 'bid',
            'file'  => UploadedFile::fake()->create('KCB tender response FINAL.pdf', 20, 'application/pdf'),
        ])->assertOk();

        $path = $deal->documents()->first()->storage_path;

        $this->assertStringNotContainsString('KCB', $path);
        $this->assertStringNotContainsString('tender', $path);
        // The real name is still on the record, so the download is named right.
        $this->assertSame('KCB tender response FINAL.pdf', $deal->documents()->first()->original_name);
    }

    public function test_a_document_altered_in_storage_fails_rather_than_returning_rubbish(): void
    {
        // AES-GCM is authenticated. Silently serving corrupted bytes would be
        // worse than an error, because nobody would know.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal();

        $this->post($this->url($deal, '/documents'), [
            'stage' => 'bid',
            'file'  => UploadedFile::fake()->createWithContent('t.pdf', 'original'),
        ])->assertOk();

        $doc = $deal->documents()->first();
        Storage::disk('opportunity_documents')->put($doc->storage_path, 'KVAULT001:tampered');

        $this->expectException(\Illuminate\Contracts\Encryption\DecryptException::class);
        DocumentVault::contents('opportunity_documents', $doc->storage_path);
    }

    public function test_documents_written_before_encryption_are_still_readable(): void
    {
        /*
         * Deploying encryption must not break every existing download at once.
         * Unsealed payloads are served as-is until `documents:encrypt-existing`
         * converts them.
         */
        Storage::disk('account_documents')->put('legacy/contract.pdf', 'signed in 2019');

        $this->assertSame(
            'signed in 2019',
            DocumentVault::contents('account_documents', 'legacy/contract.pdf'),
        );
    }

    public function test_customer_contracts_are_encrypted_too(): void
    {
        // The instruction covered pipeline documents, customer contracts and
        // customer PII; they share one vault rather than three implementations.
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);

        $this->post("/api/v1/accounts/{$this->account->id}/attachments?org={$this->org->slug}", [
            'document_type' => 'contract',
            'file' => UploadedFile::fake()->createWithContent('c.pdf', 'CONTRACT VALUE 1,200,000'),
        ])->assertCreated();

        $raw = Storage::disk('account_documents')->get($this->account->attachments()->first()->storage_path);

        $this->assertStringNotContainsString('CONTRACT VALUE', $raw);
        $this->assertTrue(DocumentVault::isSealed($raw));
    }

    // ── Commercial elevation ──────────────────────────────────────────────

    public function test_a_deal_reads_without_its_figures_by_default(): void
    {
        // Seeing the pipeline is not seeing what the company bids and makes.
        $this->actor([Permission::PIPELINE_VIEW]);
        $deal = $this->deal(['amount' => 1500000, 'confirmed_budget' => 30000]);

        $res = $this->getJson($this->url($deal, '/pipeline'))->assertOk();

        $this->assertFalse($res->json('data.commercials_visible'));
        $this->assertNull($res->json('data.budget.amount'));
    }

    public function test_the_elevation_reveals_them(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_VIEW_COMMERCIALS]);
        $deal = $this->deal(['amount' => 1500000, 'confirmed_budget' => 30000]);

        $res = $this->getJson($this->url($deal, '/pipeline'))->assertOk();

        $this->assertTrue($res->json('data.commercials_visible'));
        $this->assertEquals(30000, $res->json('data.budget.amount'));
    }

    public function test_figures_are_withheld_as_null_rather_than_dropped(): void
    {
        /*
         * A missing key would make a restricted view look like a deal with no
         * figures recorded, which is a different and misleading claim. The
         * screen needs to show the row and say why it is masked.
         */
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal();

        $this->postJson($this->url($deal, '/estimates'), [
            'name' => 'Phase 1', 'revenue' => 30000, 'cost' => 20000, 'profit' => 10000,
        ])->assertOk();

        $this->actor([Permission::PIPELINE_VIEW]);
        $estimate = $this->getJson($this->url($deal, '/pipeline'))->assertOk()->json('data.estimates.0');

        $this->assertSame('Phase 1', $estimate['name']);
        $this->assertArrayHasKey('revenue', $estimate);
        $this->assertNull($estimate['revenue']);
        $this->assertNull($estimate['profit']);
    }

    public function test_a_bid_bond_and_bidding_value_are_commercial(): void
    {
        // What we lodged and what we bid are the two figures a competitor
        // would most like to have.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal();

        $this->postJson($this->url($deal, '/bids'), [
            'tender_number' => '#TR1', 'bid_bond_value' => 500000,
            'bidding_value' => 4200000, 'bidding_cost' => 3000000,
        ])->assertOk();

        $this->actor([Permission::PIPELINE_VIEW]);
        $bid = $this->getJson($this->url($deal, '/pipeline'))->assertOk()->json('data.bids.0');

        $this->assertSame('#TR1', $bid['tender_number']);
        $this->assertNull($bid['bid_bond_value']);
        $this->assertNull($bid['bidding_value']);
        $this->assertNull($bid['bidding_profit']);
    }

    public function test_the_deal_list_does_not_leak_what_the_pipeline_withholds(): void
    {
        // Otherwise the elevation is decorative: the other endpoint is read
        // instead.
        $this->actor([Permission::PIPELINE_VIEW]);
        $this->deal(['amount' => 1500000]);

        $row = $this->getJson("/api/v1/opportunities?org={$this->org->slug}")->assertOk()->json('data.0');

        $this->assertNull($row['amount']);
        $this->assertFalse($row['commercials_visible']);
    }

    public function test_the_customer_summary_shows_counts_but_not_values(): void
    {
        // "Three deals in play" is account management; what they are worth is
        // commercial, and aggregating it does not make it less so.
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::PIPELINE_VIEW]);
        $this->deal(['amount' => 1500000]);

        $summary = $this->getJson("/api/v1/accounts/{$this->account->id}/summary?org={$this->org->slug}")
            ->assertOk()->json('data');

        $this->assertSame(1, $summary['total_deals']);
        $this->assertNull($summary['won_value']);
        $this->assertNull($summary['pipeline_value']);
    }

    public function test_stage_progress_and_documents_stay_readable_without_the_elevation(): void
    {
        // A presales engineer needs the deal and does not need the margin.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal();

        $this->post($this->url($deal, '/documents'), [
            'stage' => 'specification',
            'file'  => UploadedFile::fake()->create('spec.pdf', 20, 'application/pdf'),
        ])->assertOk();

        $this->actor([Permission::PIPELINE_VIEW]);
        $res = $this->getJson($this->url($deal, '/pipeline'))->assertOk();

        $this->assertNotEmpty($res->json('data.stages'));
        $this->assertCount(1, $res->json('data.documents.specification'));
    }
}
