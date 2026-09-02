<?php

namespace Tests\Feature\Vendor;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Training\VendorPartner;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A tier states what it costs to hold, and we can tell whether we hold it.
 *
 * The old tier stored "3" under min_certifications without saying which
 * three, so nothing could check it and `is_current` was a flag somebody set
 * by hand. A tier now names its training, assessments and certifications, and
 * carries the seats behind any revenue commitment — which together is
 * something measurable.
 */
class TierComplianceTest extends TestCase
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

    private function vendor(): array
    {
        return $this->postJson($this->url('/vendors'), [
            'name' => 'Acme Networks', 'roles' => ['distributor', 'oem'],
        ])->assertStatus(201)->json('data');
    }

    private function trainingItem(string $title, string $type = 'certification'): int
    {
        return DB::table('training_items')->insertGetId([
            'uuid' => (string) Str::uuid(), 'organization_id' => $this->org->id,
            'title' => $title, 'type' => $type,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Somebody here who has passed it, optionally expiring on a date. */
    private function certify(int $itemId, ?string $expiry = null): void
    {
        $employee = Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'organization_id' => $this->org->id,
            'primary_organization_id' => $this->org->id,
            'first_name' => 'T', 'last_name' => 'P',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);

        DB::table('employee_training_records')->insert([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->org->id,
            'employee_id' => $employee->id, 'training_item_id' => $itemId,
            'status' => 'completed',
            'certificate_expiry_date' => $expiry,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function addTier(int $vendorId, array $payload): array
    {
        return $this->postJson($this->url("/vendors/{$vendorId}/tiers"), $payload)
            ->assertStatus(201)->json('data');
    }

    private function compliance(int $vendorId): array
    {
        return $this->getJson($this->url("/vendors/{$vendorId}/tiers/compliance"))
            ->assertOk()->json('data');
    }

    // ── Named requirements ────────────────────────────────────────────────

    public function test_a_tier_requires_named_things_and_counts_who_holds_them(): void
    {
        $this->actor();
        $vendor = $this->vendor();
        $exam   = $this->trainingItem('NSE 4 Certification');

        $this->addTier($vendor['id'], [
            'tier_name' => 'Gold Partner', 'is_current' => true,
            'tier_requirements' => [[
                'requirement_type' => 'certification',
                'training_item_id' => $exam,
                'required_headcount' => 2,
            ]],
        ]);

        // Nobody holds it yet.
        $first = $this->compliance($vendor['id'])[0];
        $this->assertSame('non_compliant', $first['status']);
        $this->assertSame(0, $first['requirements'][0]['held']);
        $this->assertStringContainsString('0 of 2 people hold it', $first['gaps'][0]);

        $this->certify($exam);
        $this->certify($exam);

        $after = $this->compliance($vendor['id'])[0];
        $this->assertSame('compliant', $after['status']);
        $this->assertSame(2, $after['requirements'][0]['held']);
        $this->assertSame([], $after['gaps']);
    }

    public function test_training_assessments_and_certifications_are_told_apart(): void
    {
        $this->actor();
        $vendor = $this->vendor();

        $tier = $this->addTier($vendor['id'], [
            'tier_name' => 'Authorised Reseller',
            'tier_requirements' => [
                ['requirement_type' => 'training',      'training_item_id' => $this->trainingItem('Product Fundamentals', 'course')],
                ['requirement_type' => 'assessment',    'training_item_id' => $this->trainingItem('Sales Accreditation', 'assessment')],
                ['requirement_type' => 'certification', 'training_item_id' => $this->trainingItem('NSE 4')],
            ],
        ]);

        // A sales accreditation is a different obligation from a week-long
        // course, and the programme lists both.
        $this->assertEqualsCanonicalizing(
            ['training', 'assessment', 'certification'],
            array_column($tier['tier_requirements'], 'requirement_type'),
        );
    }

    public function test_an_expired_certificate_stops_counting_towards_the_tier(): void
    {
        $this->actor();
        $vendor = $this->vendor();
        $exam   = $this->trainingItem('NSE 4');

        $this->addTier($vendor['id'], [
            'tier_name' => 'Gold',
            'tier_requirements' => [[
                'requirement_type' => 'certification', 'training_item_id' => $exam,
                'required_headcount' => 1,
            ]],
        ]);

        // The record stays as history; it stops counting the day it lapses,
        // which is the whole reason expiry is tracked.
        $this->certify($exam, now()->subDay()->toDateString());

        $status = $this->compliance($vendor['id'])[0];
        $this->assertSame(0, $status['requirements'][0]['held']);
        $this->assertSame('non_compliant', $status['status']);
    }

    public function test_a_certificate_about_to_lapse_warns_before_it_fails(): void
    {
        $this->actor();
        $vendor = $this->vendor();
        $exam   = $this->trainingItem('NSE 4');

        $this->addTier($vendor['id'], [
            'tier_name' => 'Gold',
            'tier_requirements' => [[
                'requirement_type' => 'certification', 'training_item_id' => $exam,
                'required_headcount' => 1,
            ]],
        ]);

        $this->certify($exam, now()->addDays(20)->toDateString());

        // The difference between a warning and a post-mortem: there is still
        // time to book the exam.
        $status = $this->compliance($vendor['id'])[0];
        $this->assertSame('at_risk', $status['status']);
        $this->assertSame(1, $status['requirements'][0]['expiring_soon']);
    }

    // ── Seats behind the money ────────────────────────────────────────────

    public function test_a_revenue_commitment_is_measured_by_its_seats(): void
    {
        $this->actor();
        $vendor = $this->vendor();

        $this->addTier($vendor['id'], [
            'tier_name' => 'Premier', 'revenue_target' => 500000, 'revenue_currency' => 'USD',
            'seats_required' => 250, 'seats_activated' => 180,
        ]);

        $status = $this->compliance($vendor['id'])[0];

        $this->assertSame('short', $status['commercial']['state']);
        $this->assertSame('non_compliant', $status['status']);
        $this->assertStringContainsString('180 of 250 seats activated', $status['gaps'][0]);
    }

    public function test_a_money_target_with_no_seats_behind_it_is_not_reported_as_passing(): void
    {
        $this->actor();
        $vendor = $this->vendor();

        $this->addTier($vendor['id'], [
            'tier_name' => 'Premier', 'revenue_target' => 500000, 'revenue_currency' => 'USD',
        ]);

        // Saying so beats a confident pass on something nobody can check.
        $status = $this->compliance($vendor['id'])[0];
        $this->assertSame('unmeasurable', $status['commercial']['state']);
        $this->assertSame('unmeasured', $status['status']);
    }

    public function test_a_tier_without_a_revenue_commitment_has_no_commercial_half(): void
    {
        $this->actor();
        $vendor = $this->vendor();
        $exam   = $this->trainingItem('NSE 4');

        $this->addTier($vendor['id'], [
            'tier_name' => 'Select',
            'tier_requirements' => [[
                'requirement_type' => 'certification', 'training_item_id' => $exam,
            ]],
        ]);
        $this->certify($exam);

        // Most product tiers are earned on people alone; inventing a zero
        // target would report every one of them as failing.
        $status = $this->compliance($vendor['id'])[0];
        $this->assertNull($status['commercial']);
        $this->assertSame('compliant', $status['status']);
    }

    // ── Scope ─────────────────────────────────────────────────────────────

    public function test_a_tier_can_be_held_on_one_product(): void
    {
        $this->actor();
        $vendor  = $this->vendor();
        $product = $this->postJson($this->url("/vendors/{$vendor['id']}/products"), [
            'name' => 'FortiGate Firewalls', 'product_type' => 'product', 'technology_space' => 'cybersecurity',
        ])->assertStatus(201)->json('data');

        $tier = $this->addTier($vendor['id'], [
            'tier_name' => 'Gold', 'scope' => 'product',
            'vendor_product_id' => $product['id'], 'is_current' => true,
        ]);

        $this->assertSame('product', $tier['scope']);
        $this->assertSame($product['id'], $tier['vendor_product_id']);
    }

    public function test_a_product_tier_must_say_which_product(): void
    {
        $this->actor();
        $vendor = $this->vendor();

        $this->postJson($this->url("/vendors/{$vendor['id']}/tiers"), [
            'tier_name' => 'Gold', 'scope' => 'product',
        ])->assertStatus(422);
    }

    public function test_marking_one_product_tier_current_leaves_the_others_alone(): void
    {
        $this->actor();
        $vendor = $this->vendor();

        $firewalls = $this->postJson($this->url("/vendors/{$vendor['id']}/products"), [
            'name' => 'Firewalls', 'product_type' => 'product', 'technology_space' => 'cybersecurity',
        ])->json('data');
        $switches = $this->postJson($this->url("/vendors/{$vendor['id']}/products"), [
            'name' => 'Switches', 'product_type' => 'product', 'technology_space' => 'infrastructure',
        ])->json('data');

        $gold = $this->addTier($vendor['id'], [
            'tier_name' => 'Gold', 'scope' => 'product',
            'vendor_product_id' => $firewalls['id'], 'is_current' => true,
        ]);
        $this->addTier($vendor['id'], [
            'tier_name' => 'Silver', 'scope' => 'product',
            'vendor_product_id' => $switches['id'], 'is_current' => true,
        ]);

        /*
         * Holding Gold on firewalls says nothing about switches. Standing
         * down every other tier when one is marked current is how a partner
         * manager loses half their programme with one click.
         */
        $tiers = collect($this->getJson($this->url("/vendors/{$vendor['id']}/tiers"))->json('data'));

        $this->assertTrue($tiers->firstWhere('id', $gold['id'])['is_current']);
        $this->assertSame(2, $tiers->where('is_current', true)->count());
    }

    public function test_a_product_from_another_vendor_is_refused(): void
    {
        $this->actor();
        $mine   = $this->vendor();
        $theirs = $this->postJson($this->url('/vendors'), [
            'name' => 'Other Ltd', 'roles' => ['supplier'],
        ])->json('data');

        $product = $this->postJson($this->url("/vendors/{$theirs['id']}/products"), [
            'name' => 'Their Product', 'product_type' => 'product', 'technology_space' => 'infrastructure',
        ])->json('data');

        $this->postJson($this->url("/vendors/{$mine['id']}/tiers"), [
            'tier_name' => 'Gold', 'scope' => 'product', 'vendor_product_id' => $product['id'],
        ])->assertStatus(422);
    }

    // ── Editing ───────────────────────────────────────────────────────────

    public function test_requirements_are_replaced_not_merged(): void
    {
        $this->actor();
        $vendor = $this->vendor();
        $first  = $this->trainingItem('First Exam');
        $second = $this->trainingItem('Second Exam');

        $tier = $this->addTier($vendor['id'], [
            'tier_name' => 'Gold',
            'tier_requirements' => [
                ['requirement_type' => 'certification', 'training_item_id' => $first],
            ],
        ]);

        // The form edits them as one list; a merge would leave something
        // somebody deleted quietly in force.
        $updated = $this->putJson($this->url("/vendors/{$vendor['id']}/tiers/{$tier['id']}"), [
            'tier_requirements' => [
                ['requirement_type' => 'certification', 'training_item_id' => $second],
            ],
        ])->assertOk()->json('data');

        $this->assertCount(1, $updated['tier_requirements']);
        $this->assertSame($second, $updated['tier_requirements'][0]['training_item_id']);
    }

    public function test_editing_other_fields_leaves_the_requirements_alone(): void
    {
        $this->actor();
        $vendor = $this->vendor();
        $exam   = $this->trainingItem('NSE 4');

        $tier = $this->addTier($vendor['id'], [
            'tier_name' => 'Gold',
            'tier_requirements' => [['requirement_type' => 'certification', 'training_item_id' => $exam]],
        ]);

        // Absent means "not editing them"; an empty array means "there are
        // none" — a difference that matters when a form posts one section.
        $updated = $this->putJson($this->url("/vendors/{$vendor['id']}/tiers/{$tier['id']}"), [
            'benefits' => 'Deal registration, 25% margin',
        ])->assertOk()->json('data');

        $this->assertCount(1, $updated['tier_requirements']);
    }

    public function test_a_requirement_pointing_at_nothing_is_refused(): void
    {
        $this->actor();
        $vendor = $this->vendor();

        $this->postJson($this->url("/vendors/{$vendor['id']}/tiers"), [
            'tier_name' => 'Gold',
            'tier_requirements' => [['requirement_type' => 'certification', 'required_headcount' => 2]],
        ])->assertStatus(422);
    }

    public function test_a_retired_training_item_cannot_be_required(): void
    {
        $this->actor();
        $vendor  = $this->vendor();
        $retired = $this->trainingItem('Withdrawn Exam');

        DB::table('training_items')->where('id', $retired)->update(['deleted_at' => now()]);

        /*
         * An obligation nobody can ever satisfy: the item is gone, so the
         * count is permanently zero and the tier reads as failing for a
         * reason no amount of studying fixes.
         */
        $this->postJson($this->url("/vendors/{$vendor['id']}/tiers"), [
            'tier_name' => 'Gold',
            'tier_requirements' => [['requirement_type' => 'certification', 'training_item_id' => $retired]],
        ])->assertStatus(422);
    }

    public function test_defining_a_tier_needs_more_than_read_access(): void
    {
        $this->actor();
        $vendor = $this->vendor();

        $this->actor([Permission::VENDORS_VIEW]);

        $this->getJson($this->url("/vendors/{$vendor['id']}/tiers/compliance"))->assertOk();
        $this->postJson($this->url("/vendors/{$vendor['id']}/tiers"), ['tier_name' => 'Gold'])
            ->assertForbidden();
    }
}
