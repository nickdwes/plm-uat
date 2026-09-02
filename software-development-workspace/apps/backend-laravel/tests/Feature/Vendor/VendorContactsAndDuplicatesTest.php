<?php

namespace Tests\Feature\Vendor;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Training\VendorPartner;
use App\Models\User;
use App\Models\Vendor\VendorContact;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contacts, and the guard against registering the same party twice.
 *
 * A duplicate supplier is not untidiness: purchase orders, invoices and
 * delivery history split across two rows, and the performance scorecard is
 * computed from exactly those. The guard hands back candidates rather than
 * guessing, and an override has to be argued for in writing.
 */
class VendorContactsAndDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
    }

    private function actor(array $permissions = [Permission::VENDORS_VIEW, Permission::VENDORS_MANAGE]): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    private function url(string $path): string
    {
        return "/api/v1{$path}?org={$this->org->slug}";
    }

    private function register(array $overrides = []): array
    {
        return $this->postJson($this->url('/vendors'), array_merge([
            'name'  => 'Acme Supplies Ltd',
            'roles' => ['supplier'],
        ], $overrides))->assertStatus(201)->json('data');
    }

    // ── Duplicates ────────────────────────────────────────────────────────

    public function test_the_same_name_comes_back_as_a_candidate_not_a_second_row(): void
    {
        $this->actor();
        $this->register();

        // Case-folded: "ACME SUPPLIES LTD" is the same firm shouting.
        $body = $this->postJson($this->url('/vendors'), [
            'name' => 'ACME SUPPLIES LTD', 'roles' => ['supplier'],
        ])->assertStatus(409)->json();

        $this->assertSame('possible_duplicate', $body['error']);
        $this->assertCount(1, $body['candidates']);
        $this->assertSame('name', $body['candidates'][0]['matched_on']);
        $this->assertSame(1, VendorPartner::count());
    }

    public function test_a_shared_registration_number_is_the_strongest_signal(): void
    {
        $this->actor();
        $this->register(['registration_number' => 'C.12345']);

        // Different trading name, same company registration — the case a
        // name-only check misses entirely.
        $body = $this->postJson($this->url('/vendors'), [
            'name' => 'Acme East Africa', 'roles' => ['supplier'],
            'registration_number' => 'c.12345',
        ])->assertStatus(409)->json();

        $this->assertSame('registration number', $body['candidates'][0]['matched_on']);
    }

    public function test_a_corporate_email_domain_raises_a_candidate(): void
    {
        $this->actor();
        $this->register(['contact_email' => 'sales@acme.co.ke']);

        $body = $this->postJson($this->url('/vendors'), [
            'name' => 'Acme Networks', 'roles' => ['supplier'],
            'contact_email' => 'accounts@acme.co.ke',
        ])->assertStatus(409)->json();

        $this->assertSame('email domain', $body['candidates'][0]['matched_on']);
    }

    public function test_a_free_mailbox_is_not_evidence_of_anything(): void
    {
        $this->actor();
        $this->register(['name' => 'One Man Band', 'contact_email' => 'someone@gmail.com']);

        // Two suppliers on gmail.com are not the same supplier.
        $this->postJson($this->url('/vendors'), [
            'name' => 'Totally Different Firm', 'roles' => ['supplier'],
            'contact_email' => 'other@gmail.com',
        ])->assertStatus(201);
    }

    public function test_overriding_the_guard_needs_a_written_reason_and_is_audited(): void
    {
        $this->actor();
        $this->register();

        // Claiming they are two firms without saying why: refused.
        $this->postJson($this->url('/vendors'), [
            'name' => 'Acme Supplies Ltd (Nairobi)', 'roles' => ['supplier'],
            'registration_number' => null, 'contact_email' => null,
            'create_anyway' => true,
        ])->assertStatus(422);

        $this->postJson($this->url('/vendors'), [
            'name' => 'Acme Supplies Ltd (Nairobi)', 'roles' => ['supplier'],
            'create_anyway' => true,
            'duplicate_reason' => 'Separate legal entity, own registration pending',
        ])->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', ['action' => 'duplicate_override']);
    }

    public function test_another_tenants_vendor_is_not_a_duplicate_here(): void
    {
        $other = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        VendorPartner::create([
            'organization_id' => $other->id, 'name' => 'Acme Supplies Ltd', 'vendor_type' => 'supplier',
        ]);

        $this->actor();

        // Registers cleanly: tenancy comes before every other question.
        $this->register();
        $this->assertSame(1, VendorPartner::where('organization_id', $this->org->id)->count());
    }

    // ── Contacts ──────────────────────────────────────────────────────────

    public function test_the_first_contact_is_primary_whether_or_not_anyone_said_so(): void
    {
        $this->actor();
        $vendor = $this->register();

        $contact = $this->postJson($this->url("/vendors/{$vendor['id']}/contacts"), [
            'first_name' => 'Grace', 'last_name' => 'Mwende',
            'category' => 'technical', 'email' => 'grace@acme.co.ke',
        ])->assertStatus(201)->json('data');

        // There is nobody else to call.
        $this->assertSame('primary', $contact['category']);
        $this->assertTrue($contact['is_primary']);
    }

    public function test_naming_a_new_primary_stands_the_old_one_down(): void
    {
        $this->actor();
        $vendor = $this->register();

        $first = $this->postJson($this->url("/vendors/{$vendor['id']}/contacts"), [
            'first_name' => 'Grace',
        ])->json('data');

        $this->postJson($this->url("/vendors/{$vendor['id']}/contacts"), [
            'first_name' => 'Peter', 'category' => 'primary',
        ])->assertStatus(201);

        // Exactly one primary — two people to ring is the same as none.
        $this->assertSame(1, VendorContact::where('vendor_partner_id', $vendor['id'])
            ->where('is_primary', true)->count());
        $this->assertFalse(VendorContact::findOrFail($first['id'])->is_primary);
    }

    public function test_the_vendor_row_follows_its_primary_contact(): void
    {
        $this->actor();
        $vendor = $this->register();

        $this->postJson($this->url("/vendors/{$vendor['id']}/contacts"), [
            'first_name' => 'Grace', 'last_name' => 'Mwende',
            'designation' => 'Account Manager', 'email' => 'grace@acme.co.ke',
            'phone' => '+254700000001',
        ])->assertStatus(201);

        // The list and the procurement screens read these columns; deriving
        // them means the summary can never name somebody who left.
        $row = VendorPartner::findOrFail($vendor['id']);
        $this->assertSame('Grace Mwende', $row->primary_contact_name);
        $this->assertSame('Account Manager', $row->primary_contact_designation);
        $this->assertSame('grace@acme.co.ke', $row->contact_email);
    }

    public function test_reading_contacts_needs_view_and_changing_them_needs_manage(): void
    {
        $this->actor();
        $vendor = $this->register();

        $this->actor([Permission::VENDORS_VIEW]);

        $this->getJson($this->url("/vendors/{$vendor['id']}/contacts"))->assertOk();
        $this->postJson($this->url("/vendors/{$vendor['id']}/contacts"), ['first_name' => 'Nope'])
            ->assertForbidden();
    }

    public function test_a_contact_from_another_vendor_is_not_reachable(): void
    {
        $this->actor();
        $mine   = $this->register(['name' => 'Mine Ltd']);
        $theirs = $this->register(['name' => 'Theirs Ltd']);

        $contact = $this->postJson($this->url("/vendors/{$theirs['id']}/contacts"), [
            'first_name' => 'Someone',
        ])->json('data');

        $this->deleteJson($this->url("/vendors/{$mine['id']}/contacts/{$contact['id']}"))
            ->assertNotFound();
    }

    // ── Hygiene ───────────────────────────────────────────────────────────

    public function test_both_registration_paths_assign_a_vendor_number(): void
    {
        $this->actor();

        $viaRegister = $this->register();

        // The compliance screen used to write the row itself and skip the
        // number entirely — where a vendor was first typed in decided
        // whether it had a reference at all.
        $viaCompliance = $this->postJson($this->url('/training/vendor-compliance'), [
            'name' => 'Compliance Path Ltd',
        ])->assertSuccessful()->json();

        $this->assertNotNull($viaRegister['vendor_number']);
        $this->assertNotNull(
            VendorPartner::where('name', 'Compliance Path Ltd')->value('vendor_number'),
        );
    }

    public function test_the_scorecard_answers_to_the_vendor_permission(): void
    {
        $this->actor();
        $vendor = $this->register();

        // It asked for `assets.view` — a neighbouring permission that let
        // asset readers rate suppliers and locked out the vendor screen's
        // own audience.
        $this->actor([Permission::ASSETS_VIEW]);
        $this->getJson($this->url("/procurement/vendors/{$vendor['id']}/scorecard"))->assertForbidden();

        $this->actor([Permission::VENDORS_VIEW]);
        $this->getJson($this->url("/procurement/vendors/{$vendor['id']}/scorecard"))->assertOk();
    }

    public function test_changing_bank_details_is_recorded_without_the_values(): void
    {
        $this->actor();
        $vendor = VendorPartner::findOrFail($this->register()['id']);

        $vendor->update(['account_number' => '0123456789', 'bank_name' => 'Equity']);

        $entry = DB::table('audit_logs')
            ->where('auditable_type', VendorPartner::class)
            ->where('action', 'updated')
            ->orderByDesc('id')->first();

        $changes = json_decode($entry->changes, true);

        // The trail records THAT the money moved, never where to — an audit
        // log quoting account numbers is a second copy of them.
        $this->assertSame(['masked' => true], $changes['account_number']);
        $this->assertSame(['masked' => true], $changes['bank_name']);
    }
}
