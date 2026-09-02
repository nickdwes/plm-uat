<?php

namespace Tests\Feature\Vendor;

use App\Models\Notification;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Training\VendorPartner;
use App\Models\User;
use App\Models\Vendor\VendorDocument;
use App\Models\Vendor\VendorDocumentReminder;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Compliance paperwork, what each role demands of it, and the expiry ladder.
 *
 * The rule under test: a supplier is fit to trade with when the roles it
 * currently holds have the documents they require, unexpired — computed, not
 * a column somebody remembered to change when a date passed.
 */
class VendorDocumentsTest extends TestCase
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

    private function register(array $roles = ['supplier'], array $overrides = []): array
    {
        return $this->postJson($this->url('/vendors'), array_merge([
            'name' => 'Acme Supplies Ltd', 'roles' => $roles,
        ], $overrides))->assertStatus(201)->json('data');
    }

    private function upload(int $vendorId, string $key, array $overrides = []): array
    {
        return $this->postJson($this->url("/vendors/{$vendorId}/documents"), array_merge([
            'file'         => File::create("{$key}.pdf", 20, 'application/pdf'),
            'document_key' => $key,
            'label'        => ucfirst(str_replace('_', ' ', $key)),
            'expiry_date'  => now()->addYear()->toDateString(),
        ], $overrides))->assertStatus(201)->json('data');
    }

    // ── Requirements follow the roles ─────────────────────────────────────

    public function test_the_checklist_is_the_union_of_what_every_role_demands(): void
    {
        $this->actor();
        $vendor = $this->register(['distributor', 'contractor_company']);

        $checklist = $this->getJson($this->url("/vendors/{$vendor['id']}/documents"))
            ->assertOk()->json('meta.checklist');

        $keys = array_column($checklist, 'document_key');

        // The distributor's authorisation letter and the contractor's
        // insurance — one company, both obligations.
        $this->assertContains('oem_authorization', $keys);
        $this->assertContains('insurance_liability', $keys);
        // And the shared baseline appears once, not twice.
        $this->assertSame(1, count(array_filter($keys, fn ($k) => $k === 'tax_compliance')));
    }

    public function test_an_individual_is_not_asked_for_a_certificate_of_incorporation(): void
    {
        $this->actor();
        $vendor = $this->postJson($this->url('/vendors'), [
            'name' => 'Jane Wanjiru', 'party_class' => 'individual',
            'roles' => ['consultant'],
        ])->assertStatus(201)->json('data');

        $keys = array_column(
            $this->getJson($this->url("/vendors/{$vendor['id']}/documents"))->json('meta.checklist'),
            'document_key',
        );

        $this->assertNotContains('certificate_of_incorporation', $keys);
        $this->assertContains('national_id', $keys);
        $this->assertContains('nda', $keys);
    }

    public function test_ending_a_role_drops_the_requirements_it_brought(): void
    {
        $this->actor();
        $vendor = $this->register(['distributor']);

        $roleId = collect($vendor['roles'])->firstWhere('role', 'distributor')['id'];
        $this->patchJson($this->url("/vendors/{$vendor['id']}/roles/{$roleId}"), [])->assertOk();

        // No roles in force, nothing demanded — and so nothing blocking.
        $body = $this->getJson($this->url("/vendors/{$vendor['id']}/documents"))->assertOk()->json('meta');
        $this->assertSame([], $body['checklist']);
        $this->assertSame([], $body['blockers']);
    }

    // ── Blockers ──────────────────────────────────────────────────────────

    public function test_a_missing_mandatory_document_blocks_and_an_optional_one_does_not(): void
    {
        $this->actor();
        $vendor = $this->postJson($this->url('/vendors'), [
            'name' => 'Jane Wanjiru', 'party_class' => 'individual', 'roles' => ['consultant'],
            // The consultant's own mandatory questions, which block alongside
            // the paperwork.
            'role_details' => ['national_id_number' => '12345678', 'specialisation' => 'Security'],
        ])->assertStatus(201)->json('data');

        foreach (['national_id', 'kra_pin', 'nda', 'bank_confirmation'] as $key) {
            $this->upload($vendor['id'], $key);
        }

        // The CV is optional — worth asking for, never worth stopping over.
        $blockers = $this->getJson($this->url("/vendors/{$vendor['id']}/documents"))->json('meta.blockers');
        $this->assertSame([], $blockers);
    }

    public function test_an_expired_document_blocks_exactly_as_a_missing_one_does(): void
    {
        $this->actor();
        $vendor = $this->register();

        foreach (['certificate_of_incorporation', 'kra_pin', 'bank_confirmation'] as $key) {
            $this->upload($vendor['id'], $key, ['is_perpetual' => true, 'expiry_date' => null]);
        }
        $this->upload($vendor['id'], 'tax_compliance', ['expiry_date' => now()->subDay()->toDateString()]);

        $blockers = $this->getJson($this->url("/vendors/{$vendor['id']}/documents"))->json('meta.blockers');

        $this->assertCount(1, $blockers);
        $this->assertStringContainsString('expired on', $blockers[0]);
    }

    public function test_a_renewal_filed_beside_the_old_one_clears_the_block(): void
    {
        $this->actor();
        $vendor = $this->register();

        $this->upload($vendor['id'], 'tax_compliance', ['expiry_date' => now()->subDay()->toDateString()]);
        // Filed alongside, not instead of — the lapsed one is still evidence
        // of what was true last year, and must not keep the vendor blocked.
        $this->upload($vendor['id'], 'tax_compliance', ['expiry_date' => now()->addYear()->toDateString()]);

        $blockers = $this->getJson($this->url("/vendors/{$vendor['id']}/documents"))->json('meta.blockers');

        $this->assertEmpty(array_filter($blockers, fn ($b) => str_contains($b, 'Tax')));
    }

    public function test_the_vendor_record_carries_its_own_blockers(): void
    {
        $this->actor();
        $vendor = $this->register();

        $shown = $this->getJson($this->url("/vendors/{$vendor['id']}"))->assertOk()->json('data');

        $this->assertNotEmpty($shown['activation_blockers']);
    }

    // ── Uploading ─────────────────────────────────────────────────────────

    public function test_a_document_must_either_expire_or_say_it_never_does(): void
    {
        $this->actor();
        $vendor = $this->register();

        // Otherwise it sits in the register with nobody able to say whether
        // it is still good.
        $this->postJson($this->url("/vendors/{$vendor['id']}/documents"), [
            'file' => File::create('thing.pdf', 10, 'application/pdf'),
            'document_key' => 'tax_compliance', 'label' => 'Tax',
        ])->assertStatus(422);
    }

    public function test_the_file_streams_back_through_the_vault(): void
    {
        $this->actor();
        $vendor   = $this->register();
        $document = $this->upload($vendor['id'], 'tax_compliance');

        $this->get($this->url("/vendors/{$vendor['id']}/documents/{$document['id']}/download"))
            ->assertOk();
    }

    public function test_uploading_needs_manage_and_reading_needs_view(): void
    {
        $this->actor();
        $vendor = $this->register();

        $this->actor([Permission::VENDORS_VIEW]);

        $this->getJson($this->url("/vendors/{$vendor['id']}/documents"))->assertOk();
        $this->postJson($this->url("/vendors/{$vendor['id']}/documents"), [
            'file' => File::create('x.pdf', 10, 'application/pdf'),
            'document_key' => 'tax_compliance', 'label' => 'Tax', 'is_perpetual' => true,
        ])->assertForbidden();
    }

    public function test_a_document_from_another_vendor_is_not_reachable(): void
    {
        $this->actor();
        $mine   = $this->register(['supplier'], ['name' => 'Mine Ltd']);
        $theirs = $this->register(['supplier'], ['name' => 'Theirs Ltd']);
        $doc    = $this->upload($theirs['id'], 'tax_compliance');

        $this->get($this->url("/vendors/{$mine['id']}/documents/{$doc['id']}/download"))
            ->assertNotFound();
    }

    // ── The expiry sweep ──────────────────────────────────────────────────

    public function test_the_sweep_warns_once_per_rung_however_often_it_runs(): void
    {
        $admin  = $this->actor();
        $vendor = $this->register();
        $this->upload($vendor['id'], 'tax_compliance', ['expiry_date' => now()->addDays(10)->toDateString()]);

        $this->artisan('vendors:remind-expiries')->assertExitCode(0);
        $firstRun = Notification::where('type', 'vendor.document_expiring')->count();

        // Twice in a day changes nothing — the sent-log is the guard.
        $this->artisan('vendors:remind-expiries')->assertExitCode(0);

        $this->assertSame(1, $firstRun);
        $this->assertSame(1, Notification::where('type', 'vendor.document_expiring')->count());
        $this->assertSame(1, VendorDocumentReminder::count());
    }

    public function test_a_group_scoped_administrator_is_warned_too(): void
    {
        /*
         * The bug this pins: a group administrator holds their role with NO
         * organisation — that is what makes it reach every tenant — so a
         * recipient query asking only for assignments naming this org found
         * nobody at all, and the sweep recorded rungs it told no one about.
         */
        $this->actor();
        $vendor = $this->register();
        $this->upload($vendor['id'], 'tax_compliance', ['expiry_date' => now()->addDays(10)->toDateString()]);

        $groupAdmin = User::factory()->create();
        $role = Role::create([
            'name' => 'g' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Group', 'scope' => 'group', 'permissions' => ['vendors.*'],
        ]);
        $groupAdmin->roles()->attach($role->id, ['organization_id' => null, 'scope' => 'group']);
        $groupAdmin->forgetCachedRoles();

        $this->artisan('vendors:remind-expiries')->assertExitCode(0);

        $this->assertTrue(
            Notification::where('type', 'vendor.document_expiring')
                ->where('user_id', $groupAdmin->id)->exists(),
            'the group administrator heard nothing',
        );
    }

    public function test_an_expired_document_keeps_warning_rather_than_going_quiet(): void
    {
        $this->actor();
        $vendor = $this->register();
        $this->upload($vendor['id'], 'tax_compliance', ['expiry_date' => now()->subWeek()->toDateString()]);

        $this->artisan('vendors:remind-expiries')->assertExitCode(0);

        $notification = Notification::where('type', 'vendor.document_expiring')->first();

        // A reminder that goes silent at the moment it comes true is the
        // least useful design available.
        $this->assertNotNull($notification);
        $this->assertStringContainsString('has expired', $notification->title);
    }

    public function test_a_perpetual_document_never_generates_a_warning(): void
    {
        $this->actor();
        $vendor = $this->register();
        $this->upload($vendor['id'], 'certificate_of_incorporation', [
            'is_perpetual' => true, 'expiry_date' => null,
        ]);

        $this->artisan('vendors:remind-expiries')->assertExitCode(0);

        $this->assertSame(0, Notification::where('type', 'vendor.document_expiring')->count());
    }

    public function test_seeding_records_the_backlog_without_telling_anyone(): void
    {
        $this->actor();
        $vendor = $this->register();
        $this->upload($vendor['id'], 'tax_compliance', ['expiry_date' => now()->subMonths(6)->toDateString()]);

        // The first run against live data would otherwise fire every rung
        // every expired document has passed, at everybody, at once.
        $this->artisan('vendors:remind-expiries --seed')->assertExitCode(0);

        $this->assertSame(1, VendorDocumentReminder::count());
        $this->assertSame(0, Notification::where('type', 'vendor.document_expiring')->count());
    }

    public function test_correcting_the_expiry_date_reopens_the_ladder(): void
    {
        $this->actor();
        $vendor   = $this->register();
        $document = $this->upload($vendor['id'], 'tax_compliance', ['expiry_date' => now()->addDays(10)->toDateString()]);

        $this->artisan('vendors:remind-expiries')->assertExitCode(0);
        $this->assertSame(1, VendorDocumentReminder::count());

        // A corrected date is a different deadline; the rungs rung against
        // the old one must not suppress every warning for the new one.
        $this->putJson($this->url("/vendors/{$vendor['id']}/documents/{$document['id']}"), [
            'expiry_date' => now()->addDays(3)->toDateString(),
        ])->assertOk();

        $this->assertSame(0, VendorDocumentReminder::count());
    }

    public function test_the_sweep_leaves_rejected_vendors_alone(): void
    {
        $this->actor();
        $vendor = $this->register();
        $this->upload($vendor['id'], 'tax_compliance', ['expiry_date' => now()->subDay()->toDateString()]);

        VendorPartner::whereKey($vendor['id'])->update(['onboarding_status' => 'rejected']);

        // A supplier nobody trades with should not generate work for the
        // people who stopped trading with it.
        $this->artisan('vendors:remind-expiries')->assertExitCode(0);

        $this->assertSame(0, Notification::where('type', 'vendor.document_expiring')->count());
    }

    // ── Requirements administration ───────────────────────────────────────

    public function test_requirements_are_administrable_and_take_effect_at_once(): void
    {
        $this->actor();
        $vendor = $this->register();

        $this->postJson($this->url('/vendors/requirements'), [
            'role' => 'supplier', 'document_key' => 'quality_policy',
            'label' => 'Quality Policy', 'requirement' => 'mandatory',
        ])->assertStatus(201);

        $blockers = $this->getJson($this->url("/vendors/{$vendor['id']}/documents"))->json('meta.blockers');

        $this->assertNotEmpty(array_filter($blockers, fn ($b) => str_contains($b, 'Quality Policy')));
    }

    public function test_deactivating_a_requirement_stops_it_blocking(): void
    {
        $this->actor();
        $vendor = $this->register();

        $requirement = collect(
            $this->getJson($this->url('/vendors/requirements'))->assertOk()->json('data'),
        )->first(fn ($r) => $r['role'] === 'supplier' && $r['document_key'] === 'tax_compliance');

        $this->postJson($this->url('/vendors/requirements'), [
            ...collect($requirement)->only(['role', 'document_key', 'label', 'requirement'])->all(),
            'is_active' => false,
        ])->assertStatus(201);

        $blockers = $this->getJson($this->url("/vendors/{$vendor['id']}/documents"))->json('meta.blockers');

        $this->assertEmpty(array_filter($blockers, fn ($b) => str_contains($b, 'Tax Compliance')));
    }

    public function test_administering_requirements_needs_manage(): void
    {
        $this->actor([Permission::VENDORS_VIEW]);

        $this->getJson($this->url('/vendors/requirements'))->assertOk();
        $this->postJson($this->url('/vendors/requirements'), [
            'role' => 'supplier', 'document_key' => 'nope', 'label' => 'Nope', 'requirement' => 'mandatory',
        ])->assertForbidden();
    }
}
