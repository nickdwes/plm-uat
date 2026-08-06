<?php

namespace Tests\Feature\Account;

use App\Models\Account\Account;
use App\Models\Account\AccountContact;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Account\ReferenceSequence;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The account record as the agreed screens define it.
 *
 * The properties worth fixing are the ones that fail silently: a reference
 * handed out twice is invisible until somebody quotes one; a contact filed as
 * Secondary while still holding the primary flag makes "who do we call" answer
 * two different people; and a timeline that treats a contract expiring as
 * contact would quietly excuse the customers nobody has rung.
 */
class AccountRecordTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('account_documents');

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
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
            'first_name' => 'Test', 'last_name' => 'User',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        return $employee;
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/accounts{$path}?org={$this->org->slug}";
    }

    private function account(): Account
    {
        return Account::create([
            'organization_id' => $this->org->id,
            'name'            => 'Customer ' . fake()->unique()->numberBetween(1, 999999),
        ]);
    }

    // ── References ────────────────────────────────────────────────────────

    public function test_a_customer_is_given_a_reference_when_it_is_created(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_CREATE]);

        $res = $this->postJson($this->url(), ['name' => 'Kenya Commercial Bank'])->assertCreated();

        $this->assertMatchesRegularExpression('/^CL-\d+$/', $res->json('data.reference'));
    }

    public function test_two_customers_never_share_a_reference(): void
    {
        // Invisible until somebody quotes one, which is why it is worth a test
        // rather than a unique index alone.
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_CREATE]);

        $refs = collect(range(1, 5))->map(fn ($i) => $this->postJson($this->url(), ['name' => "Customer {$i}"])
            ->assertCreated()->json('data.reference'));

        $this->assertCount(5, $refs->unique());
    }

    public function test_an_imported_reference_is_kept_rather_than_reallocated(): void
    {
        // The legacy book arrives with references people already quote.
        Account::create([
            'organization_id' => $this->org->id, 'name' => 'Legacy Customer', 'reference' => 'CL-00042',
        ]);

        $next = ReferenceSequence::next('CL', 'accounts', $this->org->id);

        $this->assertSame('CL-00042', Account::where('name', 'Legacy Customer')->value('reference'));
        // 42 is below the floor, so the series still starts where it should
        // rather than continuing from an imported low number.
        $this->assertSame('CL-10000', $next);
    }

    public function test_a_reference_carries_on_from_the_highest_already_taken(): void
    {
        Account::create(['organization_id' => $this->org->id, 'name' => 'High', 'reference' => 'CL-20500']);

        $this->assertSame('CL-20501', ReferenceSequence::next('CL', 'accounts', $this->org->id));
    }

    public function test_a_reference_that_is_not_numbered_does_not_stop_the_series(): void
    {
        // One hand-typed reference should not take the allocator down.
        Account::create(['organization_id' => $this->org->id, 'name' => 'Odd', 'reference' => 'CL-LEGACY']);

        $this->assertSame('CL-10000', ReferenceSequence::next('CL', 'accounts', $this->org->id));
    }

    public function test_references_are_counted_per_organisation(): void
    {
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        Account::create(['organization_id' => $beta->id, 'name' => 'Theirs', 'reference' => 'CL-90000']);

        $this->assertSame('CL-10000', ReferenceSequence::next('CL', 'accounts', $this->org->id));
    }

    // ── Status and type stop overlapping ──────────────────────────────────

    public function test_a_new_customer_is_pending_rather_than_prospect(): void
    {
        // Prospect is a type now. A customer that is a Client of status
        // prospect is the state this change exists to make unrepresentable.
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_CREATE]);

        $this->postJson($this->url(), ['name' => 'New Co'])
            ->assertCreated()->assertJsonPath('data.status', 'pending');
    }

    public function test_prospect_is_no_longer_an_accepted_status(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_CREATE]);

        $this->postJson($this->url(), ['name' => 'New Co', 'status' => 'prospect'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    // ── Contacts ──────────────────────────────────────────────────────────

    public function test_a_contact_category_and_the_primary_flag_cannot_disagree(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->account();

        $this->postJson($this->url("/{$account->id}/contacts"), ['name' => 'Angie Mumo'])->assertCreated();
        $this->postJson($this->url("/{$account->id}/contacts"), [
            'name' => 'James Mwangi', 'category' => 'primary',
        ])->assertCreated();

        $contacts = AccountContact::where('account_id', $account->id)->get();

        foreach ($contacts as $c) {
            $this->assertSame($c->category === 'primary', $c->is_primary,
                'Category and is_primary must always say the same thing.');
        }
        $this->assertSame(1, $contacts->where('is_primary', true)->count());
    }

    public function test_the_first_contact_is_primary_without_being_asked(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->account();

        $this->postJson($this->url("/{$account->id}/contacts"), ['name' => 'Angie Mumo', 'category' => 'hr'])
            ->assertCreated()->assertJsonPath('data.category', 'primary');
    }

    public function test_demoting_the_only_primary_promotes_somebody_else(): void
    {
        // Contacts but no primary makes "who do we call" unanswerable and
        // nothing on screen shows it.
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->account();

        $first = $this->postJson($this->url("/{$account->id}/contacts"), ['name' => 'Angie Mumo'])->json('data.id');
        $this->postJson($this->url("/{$account->id}/contacts"), ['name' => 'James Mwangi'])->assertCreated();

        $this->putJson($this->url("/{$account->id}/contacts/{$first}"), [
            'first_name' => 'Angie', 'category' => 'hr',
        ])->assertOk();

        $this->assertSame(1, AccountContact::where('account_id', $account->id)->where('is_primary', true)->count());
    }

    public function test_a_single_name_field_is_split_into_the_two_the_record_keeps(): void
    {
        // The form asks for one name; an import from the legacy book has the
        // halves separately, and a merged column would throw that away.
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->account();

        $res = $this->postJson($this->url("/{$account->id}/contacts"), ['name' => 'Angie Mumo'])->assertCreated();

        $this->assertSame('Angie', $res->json('data.first_name'));
        $this->assertSame('Mumo', $res->json('data.last_name'));
    }

    // ── Activities ────────────────────────────────────────────────────────

    public function test_logging_a_call_records_when_we_last_spoke_to_them(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW]);
        $account = $this->account();

        $this->postJson($this->url("/{$account->id}/activities"), [
            'type' => 'call', 'body' => 'Discussed the renewal.',
        ])->assertCreated();

        $this->assertNotNull($account->fresh()->last_contact_at);
    }

    public function test_a_call_logged_late_lands_on_the_day_it_happened(): void
    {
        // Otherwise "when did we last speak to them" is answered by somebody's
        // admin habits rather than by the call.
        $this->actor([Permission::ACCOUNTS_VIEW]);
        $account = $this->account();

        $when = now()->subDays(3)->startOfHour();

        $res = $this->postJson($this->url("/{$account->id}/activities"), [
            'type' => 'call', 'body' => 'Logged late.', 'occurred_at' => $when->toIso8601String(),
        ])->assertCreated();

        $this->assertSame($when->toIso8601String(), $res->json('data.occurred_at'));
    }

    public function test_an_older_entry_does_not_make_a_customer_look_recently_contacted(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW]);
        $account = $this->account();

        $this->postJson($this->url("/{$account->id}/activities"), ['body' => 'Today'])->assertCreated();
        $recent = $account->fresh()->last_contact_at;

        $this->postJson($this->url("/{$account->id}/activities"), [
            'body' => 'Last month', 'occurred_at' => now()->subMonth()->toIso8601String(),
        ])->assertCreated();

        $this->assertEquals($recent, $account->fresh()->last_contact_at);
    }

    public function test_a_system_entry_is_not_treated_as_having_spoken_to_anyone(): void
    {
        // A contract expiring is not contact, and counting it would excuse the
        // customers nobody has rung.
        $account = $this->account();

        app(\App\Services\Account\AccountActivityService::class)
            ->record($account, 'contract', 'A contract expired.');

        $this->assertNull($account->fresh()->last_contact_at);
        $this->assertSame(1, $account->activities()->count());
    }

    public function test_an_activity_cannot_be_dated_into_the_future(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW]);

        $this->postJson($this->url("/{$this->account()->id}/activities"), [
            'body' => 'Not yet', 'occurred_at' => now()->addWeek()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('occurred_at');
    }

    // ── Attachments ───────────────────────────────────────────────────────

    public function test_a_document_can_be_filed_against_a_contract(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->account();

        $contract = $account->contracts()->create([
            'organization_id' => $this->org->id, 'name' => 'Support',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ]);

        $this->post($this->url("/{$account->id}/attachments"), [
            'document_type' => 'contract',
            'account_contract_id' => $contract->id,
            'file' => UploadedFile::fake()->create('signed.pdf', 40, 'application/pdf'),
        ])->assertCreated();

        $this->assertSame(1, $contract->attachments()->count());
    }

    public function test_a_document_cannot_be_filed_against_another_customers_contract(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $mine  = $this->account();
        $other = $this->account();

        $theirs = $other->contracts()->create([
            'organization_id' => $this->org->id, 'name' => 'Theirs',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ]);

        $this->post($this->url("/{$mine->id}/attachments"), [
            'account_contract_id' => $theirs->id,
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('account_contract_id');
    }

    public function test_a_document_is_not_reachable_without_seeing_the_customer(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->account();

        $id = $this->post($this->url("/{$account->id}/attachments"), [
            'file' => UploadedFile::fake()->create('terms.pdf', 10, 'application/pdf'),
        ])->assertCreated()->json('data.id');

        $this->get($this->url("/{$account->id}/attachments/{$id}/download"))->assertOk();

        // Somebody with no claim on the customer master at all.
        $this->actor(['leave.view']);
        $this->get($this->url("/{$account->id}/attachments/{$id}/download"))->assertForbidden();
    }

    public function test_removing_a_document_keeps_the_evidence_it_existed(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->account();

        $id = $this->post($this->url("/{$account->id}/attachments"), [
            'file' => UploadedFile::fake()->create('terms.pdf', 10, 'application/pdf'),
        ])->json('data.id');

        $this->deleteJson($this->url("/{$account->id}/attachments/{$id}"))->assertOk();

        $this->assertSame(0, $account->attachments()->count());
        $this->assertSame(1, $account->attachments()->withTrashed()->count());
    }

    // ── Contracts ─────────────────────────────────────────────────────────

    public function test_completing_a_contract_stamps_when_it_finished(): void
    {
        // Its own action rather than an edit, so status and completed_at
        // cannot disagree about whether it is done.
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->account();

        $id = $this->postJson($this->url("/{$account->id}/contracts"), [
            'name' => 'Support', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ])->assertCreated()->json('data.id');

        $res = $this->postJson($this->url("/{$account->id}/contracts/{$id}/complete"))->assertOk();

        $this->assertSame('completed', $res->json('data.status'));
        $this->assertNotNull($res->json('data.completed_at'));
    }

    public function test_a_completed_contract_is_not_also_reported_as_expired(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->account();

        $id = $this->postJson($this->url("/{$account->id}/contracts"), [
            'name' => 'Finished', 'start_date' => '2024-01-01', 'end_date' => '2025-01-01',
        ])->json('data.id');

        $res = $this->postJson($this->url("/{$account->id}/contracts/{$id}/complete"))->assertOk();

        $this->assertFalse($res->json('data.is_expired'));
    }

    public function test_an_unknown_contract_type_is_refused(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);

        $this->postJson($this->url("/{$this->account()->id}/contracts"), [
            'name' => 'Odd', 'type' => 'handshake',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    // ── Overview ──────────────────────────────────────────────────────────

    public function test_the_record_names_who_to_call_without_loading_every_contact(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->account();

        $this->postJson($this->url("/{$account->id}/contacts"), [
            'name' => 'Angie Mumo', 'designation' => 'Head of IT', 'email' => 'angie@kcb.co.ke',
        ])->assertCreated();

        $res = $this->getJson($this->url("/{$account->id}"))->assertOk();

        $this->assertSame('Angie Mumo', $res->json('data.primary_contact.name'));
        $this->assertSame('Head of IT', $res->json('data.primary_contact.role'));
    }

    public function test_the_avatar_initials_are_derived_once_rather_than_per_screen(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_CREATE]);

        $this->postJson($this->url(), ['name' => 'Kenya Commercial Bank'])
            ->assertCreated()->assertJsonPath('data.initials', 'KCB');
    }

    public function test_trips_taken_for_a_customer_are_reachable_from_the_customer(): void
    {
        // The link existed since Travel was built; nothing read it from here.
        $employee = $this->actor([Permission::ACCOUNTS_VIEW]);
        $account  = $this->account();

        \App\Models\Travel\TravelRequest::create([
            'organization_id' => $this->org->id, 'employee_id' => $employee->id,
            'account_id' => $account->id,
            'departure_date' => '2026-09-01', 'return_date' => '2026-09-03',
        ]);

        $this->getJson($this->url("/{$account->id}/travels"))
            ->assertOk()->assertJsonCount(1, 'data');
    }
}
