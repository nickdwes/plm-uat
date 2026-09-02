<?php

namespace Tests\Feature\Authorization;

use App\Models\Account\Account;
use App\Models\Account\AccountContact;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Organization;
use App\Models\Pipeline\Lead;
use App\Models\Role;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Data classification — increment 5 of the epic.
 *
 * Three classes, one rule each: customer contact details answer to
 * `accounts.view_contacts` (masked, never blank), confidential documents to
 * `documents.view_confidential` (hidden on listing AND refused on download),
 * and the audit trail answers to the same permissions that classify each
 * field on its own screen — it must not be a side-channel around them.
 */
class DataClassificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        DefaultReferenceData::seedFor($this->org->id);
    }

    private function actor(array $permissions): User
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

    private function makeAccountWithContact(): Account
    {
        $account = Account::create([
            'organization_id' => $this->org->id, 'name' => 'Acme Ltd', 'status' => 'active',
        ]);
        AccountContact::create([
            'organization_id' => $this->org->id, 'account_id' => $account->id,
            'first_name' => 'Jane', 'last_name' => 'Doe',
            'email' => 'jane.doe@acme.com', 'phone' => '+254712345678',
        ]);

        return $account;
    }

    // ── Customer contacts ─────────────────────────────────────────────────

    public function test_contact_details_arrive_masked_without_the_permission(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW]);
        $account = $this->makeAccountWithContact();

        $contact = $this->getJson($this->url("/accounts/{$account->id}"))
            ->assertOk()->json('data.contacts.0');

        // Masked, never blank: the record stays usable, the reach is hidden.
        $this->assertFalse($contact['contacts_visible']);
        $this->assertSame('j*******@acme.com', $contact['email']);
        $this->assertStringStartsWith('j', $contact['email']);
        $this->assertStringContainsString('@acme.com', $contact['email']);
        $this->assertStringNotContainsString('jane.doe', $contact['email']);
        $this->assertStringNotContainsString('345678', substr($contact['phone'], 0, -4) ?: '');
        $this->assertStringEndsWith('5678', $contact['phone']);
        // Who the person is stays open — only how to reach them is classified.
        $this->assertSame('Jane', $contact['first_name']);
    }

    public function test_the_permission_opens_the_contact_details(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_VIEW_CONTACTS]);
        $account = $this->makeAccountWithContact();

        $contact = $this->getJson($this->url("/accounts/{$account->id}"))
            ->assertOk()->json('data.contacts.0');

        $this->assertTrue($contact['contacts_visible']);
        $this->assertSame('jane.doe@acme.com', $contact['email']);
        $this->assertSame('+254712345678', $contact['phone']);
    }

    public function test_lead_stakeholders_are_the_same_asset(): void
    {
        $this->actor([Permission::PIPELINE_VIEW]);

        $lead = Lead::create([
            'organization_id' => $this->org->id, 'name' => 'Acme rollout', 'status' => 'open',
        ]);
        DB::table('lead_stakeholders')->insert([
            'organization_id' => $this->org->id, 'lead_id' => $lead->id,
            'name' => 'Sam CTO', 'email' => 'sam@acme.com', 'phone' => '+254798765432',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->getJson($this->url("/leads/{$lead->id}"))
            ->assertOk()->json('data');

        $this->assertFalse($data['contacts_visible']);
        $this->assertStringNotContainsString('sam@', $data['stakeholders'][0]['email']);
        $this->assertStringEndsWith('@acme.com', $data['stakeholders'][0]['email']);
    }

    // ── Confidential documents ────────────────────────────────────────────

    private function makeEmployeeWithDocs(User $viewer): array
    {
        $employee = Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id, 'first_name' => 'T', 'last_name' => 'P',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01', 'user_id' => $viewer->id,
        ]);
        $plain = EmployeeDocument::create([
            'employee_id' => $employee->id, 'uploaded_by' => $viewer->id, 'category' => 'contract',
            'original_name' => 'contract.pdf', 'storage_path' => 'x/contract.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 100,
        ]);
        $secret = EmployeeDocument::create([
            'employee_id' => $employee->id, 'uploaded_by' => $viewer->id, 'category' => 'disciplinary',
            'is_confidential' => true,
            'original_name' => 'investigation.pdf', 'storage_path' => 'x/investigation.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 100,
        ]);

        return [$employee, $plain, $secret];
    }

    public function test_confidential_documents_hide_even_from_their_subject(): void
    {
        // The employee themself, reading their own file — which is the point:
        // an investigation about you is not yours to read.
        $user = $this->actor([Permission::EMPLOYEES_VIEW_OWN]);
        [$employee, $plain, $secret] = $this->makeEmployeeWithDocs($user);

        $res = $this->getJson($this->url("/employees/{$employee->id}/documents"))->assertOk();

        $names = array_column($res->json('data'), 'original_name');
        $this->assertContains('contract.pdf', $names);
        $this->assertNotContains('investigation.pdf', $names);
        $this->assertFalse($res->json('can_flag_confidential'));

        // A remembered id is not a way round the listing.
        $this->getJson($this->url("/employees/{$employee->id}/documents/{$secret->id}/download"))
            ->assertForbidden();
    }

    public function test_the_permission_opens_the_confidential_class(): void
    {
        $user = $this->actor([Permission::EMPLOYEES_VIEW_OWN, Permission::DOCUMENTS_VIEW_CONFIDENTIAL]);
        [$employee] = $this->makeEmployeeWithDocs($user);

        $res = $this->getJson($this->url("/employees/{$employee->id}/documents"))->assertOk();

        $this->assertContains('investigation.pdf', array_column($res->json('data'), 'original_name'));
        $this->assertTrue($res->json('can_flag_confidential'));
    }

    public function test_flagging_confidential_needs_the_permission(): void
    {
        $user = $this->actor([Permission::EMPLOYEES_VIEW_OWN, Permission::EMPLOYEES_UPDATE]);
        [$employee] = $this->makeEmployeeWithDocs($user);

        // Without the permission you would upload a file you can never see
        // again — refused rather than allowed into a black hole.
        $this->postJson($this->url("/employees/{$employee->id}/documents"), [
            'file'            => \Illuminate\Http\Testing\File::fake()->create('note.pdf', 10, 'application/pdf'),
            'category'        => 'disciplinary',
            'is_confidential' => '1',
        ])->assertForbidden();
    }

    public function test_a_masked_reader_cannot_clobber_the_real_contact_details(): void
    {
        /*
         * The trap this guards: an updater without view_contacts received the
         * email MASKED — and the mask is itself a syntactically valid email,
         * so validation alone would happily write it over the real address
         * the moment they saved an unrelated edit to the same card.
         */
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
        $account = $this->makeAccountWithContact();
        $contact = $account->contacts()->first();

        $this->putJson($this->url("/accounts/{$account->id}/contacts/{$contact->id}"), [
            'first_name' => 'Jane', 'last_name' => 'Doe', 'designation' => 'CTO',
            'email' => 'j*******@acme.com', 'phone' => '****5678',
        ])->assertOk();

        $contact->refresh();
        $this->assertSame('CTO', $contact->designation);            // the edit they meant
        $this->assertSame('jane.doe@acme.com', $contact->email);    // the data they couldn't see
        $this->assertSame('+254712345678', $contact->phone);
    }

    public function test_a_plain_document_still_downloads(): void
    {
        // Regression pin: download() declared Illuminate\Http\Response while
        // the vault streams a Symfony StreamedResponse — every download 500ed.
        $user = $this->actor([Permission::EMPLOYEES_VIEW_OWN, Permission::EMPLOYEES_UPDATE]);

        $employee = Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id, 'first_name' => 'T', 'last_name' => 'P',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01', 'user_id' => $user->id,
        ]);

        $id = $this->postJson($this->url("/employees/{$employee->id}/documents"), [
            'file'     => \Illuminate\Http\Testing\File::fake()->create('contract.pdf', 10, 'application/pdf'),
            'category' => 'contract',
        ])->assertStatus(201)->json('data.id');

        $this->get($this->url("/employees/{$employee->id}/documents/{$id}/download"))
            ->assertOk();
    }

    // ── The audit trail is not a side-channel ─────────────────────────────

    public function test_audit_diffs_redact_for_readers_without_the_field_permission(): void
    {
        DB::table('audit_logs')->insert([
            'organization_id' => $this->org->id, 'action' => 'updated',
            'auditable_type' => 'App\Models\Account\AccountContact', 'auditable_id' => 1,
            'description' => 'AccountContact #1 updated',
            'changes' => json_encode([
                'email' => ['old' => 'j***@acme.com', 'new' => 'j***@other.com'],
                'is_active' => ['old' => true, 'new' => false],
            ]),
            'created_at' => now(),
        ]);

        // An auditor who cannot open a contact card must not read its
        // old→new here — but the operational half of the diff stays theirs.
        $this->actor([Permission::AUDIT_VIEW]);
        $row = collect($this->getJson($this->url('/audit-logs'))->assertOk()->json('data'))
            ->firstWhere('subject', 'AccountContact #1');

        $this->assertTrue($row['values_redacted']);
        $this->assertSame(['masked' => true], $row['changes']['email']);
        $this->assertSame(['old' => true, 'new' => false], $row['changes']['is_active']);

        // The permission that opens the screen opens the trail.
        $this->actor([Permission::AUDIT_VIEW, Permission::ACCOUNTS_VIEW_CONTACTS]);
        $row = collect($this->getJson($this->url('/audit-logs'))->assertOk()->json('data'))
            ->firstWhere('subject', 'AccountContact #1');

        $this->assertFalse($row['values_redacted']);
        $this->assertSame('j***@other.com', $row['changes']['email']['new']);
    }
}
