<?php

namespace Tests\Feature\Vendor;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Training\VendorPartner;
use App\Models\User;
use App\Models\Vendor\VendorApproval;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Onboarding a supplier is a chain, not a button.
 *
 * What these pin: the states are a map (so `approve` can no longer be called
 * on a draft), nobody signs their own submission, nothing is activated while
 * its paperwork is missing or expired, and banking and legal identity need a
 * second pair of eyes.
 */
class VendorOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
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

    /** Registers vendors; may submit, but may not decide. */
    private function registrar(): User
    {
        return $this->actor([Permission::VENDORS_VIEW, Permission::VENDORS_MANAGE]);
    }

    /** Decides them. */
    private function approver(): User
    {
        return $this->actor([Permission::VENDORS_VIEW, Permission::VENDORS_MANAGE, Permission::VENDORS_APPROVE]);
    }

    private function url(string $path): string
    {
        return "/api/v1{$path}?org={$this->org->slug}";
    }

    /** A supplier with every mandatory document in order. */
    private function readyVendor(): array
    {
        $vendor = $this->postJson($this->url('/vendors'), [
            'name' => 'Acme Supplies Ltd', 'roles' => ['supplier'],
        ])->assertStatus(201)->json('data');

        foreach (['certificate_of_incorporation', 'tax_compliance', 'kra_pin', 'bank_confirmation'] as $key) {
            $this->postJson($this->url("/vendors/{$vendor['id']}/documents"), [
                'file'         => File::create("{$key}.pdf", 20, 'application/pdf'),
                'document_key' => $key,
                'label'        => ucfirst(str_replace('_', ' ', $key)),
                'expiry_date'  => now()->addYear()->toDateString(),
            ])->assertStatus(201);
        }

        return $vendor;
    }

    /** Walk the chain to the last pending stage, signing as an approver. */
    private function signUntilLast(int $vendorId): void
    {
        while (true) {
            $pending = VendorApproval::where('vendor_partner_id', $vendorId)
                ->where('status', 'pending')->orderBy('sequence')->get();

            if ($pending->count() <= 1) {
                return;
            }

            $this->postJson($this->url("/vendors/{$vendorId}/approve"))->assertOk();
        }
    }

    // ── The transition map ────────────────────────────────────────────────

    public function test_a_draft_cannot_be_approved_without_being_reviewed(): void
    {
        $this->registrar();
        $vendor = $this->readyVendor();

        // The hole this closes: `approve` used to work from any status, so
        // the review everybody believed in could be skipped with one call.
        $this->approver();

        $this->postJson($this->url("/vendors/{$vendor['id']}/approve"))
            ->assertStatus(422);

        $this->assertSame('draft', VendorPartner::findOrFail($vendor['id'])->onboarding_status);
    }

    public function test_the_chain_is_materialised_at_submission(): void
    {
        $this->registrar();
        $vendor = $this->readyVendor();

        $body = $this->postJson($this->url("/vendors/{$vendor['id']}/submit"))->assertOk()->json('data');

        $this->assertSame('pending_review', $body['onboarding_status']);
        $this->assertCount(4, $body['approvals']);
        $this->assertSame('registration_review', $body['approvals'][0]['stage']);
        $this->assertSame('Registration review', $body['approvals'][0]['label']);
    }

    public function test_a_refused_vendor_can_be_corrected_and_resubmitted(): void
    {
        $registrar = $this->registrar();
        $vendor    = $this->readyVendor();
        $this->postJson($this->url("/vendors/{$vendor['id']}/submit"))->assertOk();

        $this->approver();
        $this->postJson($this->url("/vendors/{$vendor['id']}/reject"), ['reason' => 'Wrong entity'])
            ->assertOk();

        Sanctum::actingAs($registrar);
        $this->postJson($this->url("/vendors/{$vendor['id']}/return-to-draft"))->assertOk();
        $this->postJson($this->url("/vendors/{$vendor['id']}/submit"))->assertOk();

        // A fresh chain, not the decisions from the previous attempt.
        $this->assertSame(4, VendorApproval::where('vendor_partner_id', $vendor['id'])
            ->where('status', 'pending')->count());
    }

    public function test_the_chain_is_on_the_record_without_pressing_anything(): void
    {
        $this->registrar();
        $vendor = $this->readyVendor();
        $this->postJson($this->url("/vendors/{$vendor['id']}/submit"))->assertOk();

        // It used to appear only in the response to a lifecycle action, so a
        // reviewer opening the page cold saw no chain at all.
        $shown = $this->getJson($this->url("/vendors/{$vendor['id']}"))->assertOk()->json('data');

        $this->assertCount(4, $shown['approvals']);
    }

    // ── Separation of duties ──────────────────────────────────────────────

    public function test_nobody_signs_their_own_submission(): void
    {
        // Holds every vendor permission there is, including approve.
        $both   = $this->approver();
        $vendor = $this->readyVendor();
        $this->postJson($this->url("/vendors/{$vendor['id']}/submit"))->assertOk();

        // Still refused: the point of a review is a second pair of eyes, and
        // the permission does not make one person into two.
        $this->postJson($this->url("/vendors/{$vendor['id']}/approve"))
            ->assertStatus(422);

        $this->approver();
        $this->postJson($this->url("/vendors/{$vendor['id']}/approve"))->assertOk();
    }

    public function test_deciding_needs_the_approve_permission_not_merely_manage(): void
    {
        $this->registrar();
        $vendor = $this->readyVendor();
        $this->postJson($this->url("/vendors/{$vendor['id']}/submit"))->assertOk();

        // A second registrar: may edit anything, may not bless it.
        $this->registrar();
        $this->postJson($this->url("/vendors/{$vendor['id']}/approve"))->assertForbidden();
        $this->postJson($this->url("/vendors/{$vendor['id']}/reject"), ['reason' => 'no'])->assertForbidden();
    }

    public function test_a_rejection_skips_the_stages_behind_it(): void
    {
        $this->registrar();
        $vendor = $this->readyVendor();
        $this->postJson($this->url("/vendors/{$vendor['id']}/submit"))->assertOk();

        $this->approver();
        $this->postJson($this->url("/vendors/{$vendor['id']}/reject"), ['reason' => 'Not a real company'])
            ->assertOk();

        // Left pending, the chain would read as still in progress.
        $this->assertSame(0, VendorApproval::where('vendor_partner_id', $vendor['id'])
            ->where('status', 'pending')->count());
        $this->assertSame(3, VendorApproval::where('vendor_partner_id', $vendor['id'])
            ->where('status', 'skipped')->count());
    }

    public function test_stages_sign_in_order_and_only_the_last_one_activates(): void
    {
        $this->registrar();
        $vendor = $this->readyVendor();
        $this->postJson($this->url("/vendors/{$vendor['id']}/submit"))->assertOk();

        $this->approver();

        for ($signed = 1; $signed <= 3; $signed++) {
            $body = $this->postJson($this->url("/vendors/{$vendor['id']}/approve"))->assertOk()->json('data');
            $this->assertSame('pending_review', $body['onboarding_status'], "activated after {$signed} stage(s)");
        }

        $body = $this->postJson($this->url("/vendors/{$vendor['id']}/approve"))->assertOk()->json('data');
        $this->assertSame('approved', $body['onboarding_status']);
        $this->assertTrue($body['is_active']);
    }

    // ── The blocking rule ─────────────────────────────────────────────────

    public function test_a_vendor_missing_its_paperwork_cannot_be_submitted(): void
    {
        $this->registrar();
        $vendor = $this->postJson($this->url('/vendors'), [
            'name' => 'Paperless Ltd', 'roles' => ['supplier'],
        ])->assertStatus(201)->json('data');

        $this->postJson($this->url("/vendors/{$vendor['id']}/submit"))
            ->assertStatus(422)
            // The reviewer needs the reason, not a boolean.
            ->assertSee('Tax Compliance Certificate', false);
    }

    public function test_a_certificate_that_lapses_mid_review_stops_the_activation(): void
    {
        $this->registrar();
        $vendor = $this->readyVendor();
        $this->postJson($this->url("/vendors/{$vendor['id']}/submit"))->assertOk();

        $this->approver();
        $this->signUntilLast($vendor['id']);

        // Documents expire between submission and signature; the last stage
        // is the activation gate, so it asks again.
        DB::table('vendor_documents')
            ->where('vendor_partner_id', $vendor['id'])
            ->where('document_key', 'tax_compliance')
            ->update(['expiry_date' => now()->subDay()->toDateString()]);

        $this->postJson($this->url("/vendors/{$vendor['id']}/approve"))->assertStatus(422);

        $this->assertSame('pending_review', VendorPartner::findOrFail($vendor['id'])->onboarding_status);
    }

    public function test_reactivating_a_suspended_vendor_rechecks_its_paperwork(): void
    {
        $this->registrar();
        $vendor = $this->readyVendor();
        $this->postJson($this->url("/vendors/{$vendor['id']}/submit"))->assertOk();

        $this->approver();
        $this->signUntilLast($vendor['id']);
        $this->postJson($this->url("/vendors/{$vendor['id']}/approve"))->assertOk();

        $this->postJson($this->url("/vendors/{$vendor['id']}/suspend"), ['reason' => 'Quality issues'])
            ->assertOk();

        // A suspension usually outlives a certificate.
        DB::table('vendor_documents')
            ->where('vendor_partner_id', $vendor['id'])
            ->where('document_key', 'tax_compliance')
            ->update(['expiry_date' => now()->subDay()->toDateString()]);

        $this->postJson($this->url("/vendors/{$vendor['id']}/reactivate"))->assertStatus(422);
    }

    // ── Sensitive changes ─────────────────────────────────────────────────

    private function approvedVendor(): array
    {
        $this->registrar();
        $vendor = $this->readyVendor();
        $this->postJson($this->url("/vendors/{$vendor['id']}/submit"))->assertOk();

        $this->approver();
        $this->signUntilLast($vendor['id']);
        $this->postJson($this->url("/vendors/{$vendor['id']}/approve"))->assertOk();

        return $vendor;
    }

    public function test_changing_bank_details_on_a_live_vendor_waits_for_a_second_person(): void
    {
        $vendor   = $this->approvedVendor();
        $proposer = $this->approver();

        $body = $this->putJson($this->url("/vendors/{$vendor['id']}"), [
            'account_number' => '9999999999',
            'city'           => 'Mombasa',
        ])->assertOk()->json('data');

        // Redirecting the account of a supplier everyone already trusts is
        // the highest-value fraud in a purchase-to-pay system.
        $this->assertSame(['account_number'], $body['pending_change']['fields']);
        $this->assertSame($proposer->id, $body['pending_change']['proposed_by']);

        $row = VendorPartner::findOrFail($vendor['id']);
        $this->assertNotSame('9999999999', $row->account_number);
        // The rest of the same submission saved normally.
        $this->assertSame('Mombasa', $row->city);
    }

    public function test_the_proposer_cannot_confirm_their_own_change(): void
    {
        $vendor = $this->approvedVendor();
        $this->approver();

        $this->putJson($this->url("/vendors/{$vendor['id']}"), ['account_number' => '9999999999'])->assertOk();

        $this->postJson($this->url("/vendors/{$vendor['id']}/pending-change/confirm"))
            ->assertStatus(422);

        // Somebody else can.
        $this->approver();
        $this->postJson($this->url("/vendors/{$vendor['id']}/pending-change/confirm"))->assertOk();

        $this->assertSame('9999999999', VendorPartner::findOrFail($vendor['id'])->account_number);
    }

    public function test_a_queued_change_never_shows_the_proposed_values(): void
    {
        $vendor = $this->approvedVendor();
        $this->approver();

        $this->putJson($this->url("/vendors/{$vendor['id']}"), ['account_number' => '9999999999'])->assertOk();

        // A screen that renders the proposed account number defeats the point
        // of making somebody confirm it.
        $this->getJson($this->url("/vendors/{$vendor['id']}"))
            ->assertOk()
            ->assertDontSee('9999999999');
    }

    public function test_the_audit_trail_never_quotes_the_proposed_account_number(): void
    {
        $vendor = $this->approvedVendor();
        $this->approver();

        $this->putJson($this->url("/vendors/{$vendor['id']}"), ['account_number' => '9999888877'])->assertOk();

        /*
         * The leak this closes: `account_number` was masked in its own diff
         * while `pending_change` — the JSON holding the proposed value —
         * was written out in clear, quoting the exact figure the mask exists
         * to keep out of the trail.
         */
        $entries = DB::table('audit_logs')
            ->where('auditable_type', VendorPartner::class)
            ->pluck('changes')->implode(' ');

        $this->assertStringNotContainsString('9999888877', $entries);
    }

    public function test_a_discarded_change_leaves_the_record_untouched(): void
    {
        $vendor = $this->approvedVendor();
        $this->approver();
        $before = VendorPartner::findOrFail($vendor['id'])->account_number;

        $this->putJson($this->url("/vendors/{$vendor['id']}"), ['account_number' => '9999999999'])->assertOk();

        $this->approver();
        $this->postJson($this->url("/vendors/{$vendor['id']}/pending-change/discard"))->assertOk();

        $row = VendorPartner::findOrFail($vendor['id']);
        $this->assertSame($before, $row->account_number);
        $this->assertNull($row->pending_change);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sensitive_change_discarded']);
    }

    public function test_editing_a_draft_needs_no_ceremony(): void
    {
        $this->registrar();
        $vendor = $this->readyVendor();

        // Nobody is paying a draft, so two signatures to fix a typo would be
        // ceremony rather than control.
        $this->putJson($this->url("/vendors/{$vendor['id']}"), ['account_number' => '1234567890'])->assertOk();

        $row = VendorPartner::findOrFail($vendor['id']);
        $this->assertSame('1234567890', $row->account_number);
        $this->assertNull($row->pending_change);
    }

    public function test_resubmitting_the_same_values_queues_nothing(): void
    {
        $vendor = $this->approvedVendor();
        $this->approver();

        $current = VendorPartner::findOrFail($vendor['id']);

        // Sending the whole form back should not queue a confirmation for
        // fields nobody touched.
        $this->putJson($this->url("/vendors/{$vendor['id']}"), [
            'name'  => $current->name,
            'notes' => 'Called about lead times',
        ])->assertOk();

        $this->assertNull(VendorPartner::findOrFail($vendor['id'])->pending_change);
    }

    public function test_confirming_a_change_needs_the_approve_permission(): void
    {
        $vendor = $this->approvedVendor();
        $this->approver();
        $this->putJson($this->url("/vendors/{$vendor['id']}"), ['account_number' => '9999999999'])->assertOk();

        $this->registrar();
        $this->postJson($this->url("/vendors/{$vendor['id']}/pending-change/confirm"))->assertForbidden();
    }
}
