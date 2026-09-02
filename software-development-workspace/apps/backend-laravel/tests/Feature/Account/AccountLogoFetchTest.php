<?php

namespace Tests\Feature\Account;

use App\Models\Account\Account;
use App\Models\Account\AccountContact;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Auto-populating client logos from their contact-email domain via Google favicons. */
class AccountLogoFetchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'T', 'scope' => 'organization',
            'permissions' => [Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_CREATE, Permission::ACCOUNTS_UPDATE],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    private function account(string $name): Account
    {
        return Account::create(['organization_id' => $this->org->id, 'name' => $name, 'status' => 'active']);
    }

    private function contact(Account $a, string $email, bool $primary = true): void
    {
        AccountContact::create([
            'organization_id' => $this->org->id, 'account_id' => $a->id,
            'first_name' => 'A', 'last_name' => 'B', 'email' => $email, 'is_primary' => $primary, 'is_active' => true,
        ]);
    }

    private function url(string $path): string
    {
        return "/api/v1/accounts{$path}?org={$this->org->slug}";
    }

    public function test_it_fetches_a_logo_from_a_corporate_contact_domain(): void
    {
        Http::fake(['www.google.com/s2/favicons*' => Http::response(str_repeat('x', 800), 200, ['Content-Type' => 'image/png'])]);

        $a = $this->account('Equity Bank');
        $this->contact($a, 'jane@equitybank.co.ke');

        $this->postJson($this->url("/{$a->id}/logo/fetch"))->assertOk();

        $a->refresh();
        $this->assertNotNull($a->logo_path);
        Storage::disk('public')->assertExists($a->logo_path);

        // It asked Google for the contact's domain.
        Http::assertSent(fn ($req) => str_contains($req->url(), 'domain=equitybank.co.ke'));
    }

    public function test_a_free_mail_only_account_has_no_derivable_domain(): void
    {
        Http::fake();
        $a = $this->account('Small Co');
        $this->contact($a, 'someone@gmail.com');

        $this->postJson($this->url("/{$a->id}/logo/fetch"))->assertStatus(422);
        $this->assertNull($a->fresh()->logo_path);
        Http::assertNothingSent();
    }

    public function test_the_globe_fallback_404_is_not_stored(): void
    {
        Http::fake(['www.google.com/s2/favicons*' => Http::response(str_repeat('g', 726), 404, ['Content-Type' => 'image/png'])]);

        $a = $this->account('Obscure Ltd');
        $this->contact($a, 'ops@obscure-ltd.co.ke');

        $this->postJson($this->url("/{$a->id}/logo/fetch"))->assertStatus(422);
        $this->assertNull($a->fresh()->logo_path);
    }

    public function test_by_domain_falls_back_through_bare_www_and_url_forms(): void
    {
        // The bare host and www host miss (404 globe); the https:// form hits.
        Http::fake([
            'www.google.com/s2/favicons?domain=kcbgroup.com*'         => Http::response(str_repeat('g', 726), 404, ['Content-Type' => 'image/png']),
            'www.google.com/s2/favicons?domain=www.kcbgroup.com*'     => Http::response(str_repeat('g', 726), 404, ['Content-Type' => 'image/png']),
            'www.google.com/s2/favicons?domain=https%3A%2F%2Fkcbgroup.com*' => Http::response(str_repeat('x', 900), 200, ['Content-Type' => 'image/png']),
        ]);

        $a = $this->account('KCB Group');
        $tally = $this->postJson($this->url('/fetch-logos-by-domain'), [
            'force' => true, 'domains' => [(string) $a->id => 'kcbgroup.com'],
        ])->assertOk()->json('data');

        $this->assertSame('fetched', $tally['results'][(string) $a->id]);
        $this->assertNotNull($a->fresh()->logo_path);
    }

    public function test_by_domain_reports_failed_when_no_form_resolves(): void
    {
        Http::fake(['www.google.com/s2/favicons*' => Http::response(str_repeat('g', 726), 404, ['Content-Type' => 'image/png'])]);

        $a = $this->account('Obscure Agency');
        $res = $this->postJson($this->url('/fetch-logos-by-domain'), [
            'domains' => [(string) $a->id => 'obscure-agency.go.ke'],
        ])->assertOk()->json('data');

        $this->assertSame('failed', $res['results'][(string) $a->id]);
        $this->assertNull($a->fresh()->logo_path);
    }

    public function test_bulk_fetch_reports_a_tally_and_skips_accounts_with_a_logo(): void
    {
        Http::fake(['www.google.com/s2/favicons*' => Http::response(str_repeat('x', 800), 200, ['Content-Type' => 'image/png'])]);

        $withDomain = $this->account('Bank A');
        $this->contact($withDomain, 'info@banka.co.ke');

        $freeMail = $this->account('Shop B');
        $this->contact($freeMail, 'shopb@yahoo.com');

        $already = $this->account('Has Logo');
        $this->contact($already, 'x@haslogo.com');
        $already->update(['logo_path' => 'account-logos/pre/existing.png']);

        $tally = $this->postJson($this->url('/fetch-logos'))->assertOk()->json('data');

        $this->assertSame(1, $tally['fetched']);    // Bank A
        $this->assertSame(1, $tally['no_domain']);  // Shop B
        $this->assertNotNull($withDomain->fresh()->logo_path);
        $this->assertSame('account-logos/pre/existing.png', $already->fresh()->logo_path);  // untouched
    }
}
