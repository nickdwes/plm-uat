<?php

namespace Tests\Feature\Travel;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Travel\TravelAllowanceRate;
use App\Models\Travel\TravelApprovalStage;
use App\Models\Travel\TravelDestination;
use App\Models\Travel\TravelExpenseCategory;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Travel configuration: destinations, categories, rates and the approval chain.
 *
 * The property that matters most here is that changing a rate cannot rewrite
 * history. A request approved in March must still show March's rates in
 * November, and an edit-in-place would change what every past request was
 * costed against without anything on screen indicating it.
 */
class TravelSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
    }

    private function actingAsWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name'         => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        Sanctum::actingAs($user);

        return $user;
    }

    private function url(string $path): string
    {
        return "/api/v1/travel/settings{$path}?org={$this->org->slug}";
    }

    private function category(): TravelExpenseCategory
    {
        return TravelExpenseCategory::create(['organization_id' => $this->org->id, 'name' => 'Meals']);
    }

    // ── Authorization ─────────────────────────────────────────────────────

    public function test_configuration_requires_the_manage_rates_permission(): void
    {
        // Being able to raise or even approve travel is not the same as
        // setting what the organisation will pay.
        $this->actingAsWith([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_APPROVE]);

        $this->getJson($this->url('/rates'))->assertForbidden();
        $this->postJson($this->url('/destinations'), ['name' => 'Mombasa'])->assertForbidden();
    }

    public function test_finance_can_configure(): void
    {
        $this->actingAsWith([Permission::TRAVEL_MANAGE_RATES]);

        $this->postJson($this->url('/destinations'), ['name' => 'Mombasa'])->assertCreated();
    }

    // ── Destinations and categories ───────────────────────────────────────

    public function test_a_destination_name_is_unique_within_the_organisation(): void
    {
        $this->actingAsWith([Permission::TRAVEL_MANAGE_RATES]);
        $this->postJson($this->url('/destinations'), ['name' => 'Mombasa'])->assertCreated();

        $this->postJson($this->url('/destinations'), ['name' => 'Mombasa'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_deleting_a_destination_retires_it_rather_than_removing_it(): void
    {
        // Requests already raised against it hold a foreign key; a deleted row
        // would leave those showing no destination at all.
        $this->actingAsWith([Permission::TRAVEL_MANAGE_RATES]);
        $id = $this->postJson($this->url('/destinations'), ['name' => 'Kisumu'])->json('data.id');

        $this->deleteJson($this->url("/destinations/{$id}"))->assertOk();

        $this->assertFalse(TravelDestination::find($id)->is_active);
        $this->assertNotNull(TravelDestination::find($id), 'The row must survive.');
    }

    // ── Rates ─────────────────────────────────────────────────────────────

    public function test_changing_a_rate_supersedes_it_instead_of_editing_it(): void
    {
        $this->actingAsWith([Permission::TRAVEL_MANAGE_RATES]);
        $category = $this->category();

        $original = $this->postJson($this->url('/rates'), [
            'travel_expense_category_id' => $category->id,
            'amount'                     => 1500,
            'effective_from'             => '2026-01-01',
        ])->assertCreated()->json('data.id');

        $this->putJson($this->url("/rates/{$original}"), [
            'travel_expense_category_id' => $category->id,
            'amount'                     => 2500,
            'effective_from'             => '2026-07-01',
        ])->assertOk();

        $old = TravelAllowanceRate::find($original);
        $this->assertEquals(1500, (float) $old->amount, 'The original amount must be untouched.');
        $this->assertSame('2026-06-30', $old->effective_to->toDateString(), 'It must be closed the day before the new one starts.');

        $this->assertSame(2, TravelAllowanceRate::count(), 'The replacement is a new row.');
    }

    public function test_a_replacement_cannot_start_before_the_rate_it_replaces(): void
    {
        // Backdating would leave two rates in force for the same period, and
        // resolution would silently pick one.
        $this->actingAsWith([Permission::TRAVEL_MANAGE_RATES]);
        $category = $this->category();

        $id = $this->postJson($this->url('/rates'), [
            'travel_expense_category_id' => $category->id,
            'amount' => 1500, 'effective_from' => '2026-06-01',
        ])->json('data.id');

        $this->putJson($this->url("/rates/{$id}"), [
            'travel_expense_category_id' => $category->id,
            'amount' => 2500, 'effective_from' => '2026-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors('effective_from');
    }

    public function test_the_default_rate_list_shows_only_what_is_in_force(): void
    {
        $this->actingAsWith([Permission::TRAVEL_MANAGE_RATES]);
        $category = $this->category();

        TravelAllowanceRate::create([
            'organization_id' => $this->org->id, 'travel_expense_category_id' => $category->id,
            'amount' => 900, 'effective_from' => '2024-01-01', 'effective_to' => '2024-12-31',
        ]);
        TravelAllowanceRate::create([
            'organization_id' => $this->org->id, 'travel_expense_category_id' => $category->id,
            'amount' => 1500, 'effective_from' => '2025-01-01',
        ]);

        $this->assertCount(1, $this->getJson($this->url('/rates'))->json('data'));
        // Appended with & — the helper already puts ?org= on the end.
        $this->assertCount(2, $this->getJson($this->url('/rates') . '&include_history=1')->json('data'));
    }

    public function test_a_category_from_another_organisation_cannot_be_priced(): void
    {
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $foreign = TravelExpenseCategory::create(['organization_id' => $beta->id, 'name' => 'Meals']);

        $this->actingAsWith([Permission::TRAVEL_MANAGE_RATES]);

        $this->postJson($this->url('/rates'), [
            'travel_expense_category_id' => $foreign->id,
            'amount' => 1500, 'effective_from' => '2026-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors('travel_expense_category_id');
    }

    // ── Approval chain ────────────────────────────────────────────────────

    public function test_the_chain_is_saved_as_a_set(): void
    {
        $this->actingAsWith([Permission::TRAVEL_MANAGE_RATES]);

        $this->putJson($this->url('/approval-stages'), ['stages' => [
            ['stage' => 'department_head', 'sequence' => 1],
            ['stage' => 'finance',         'sequence' => 3],
            ['stage' => 'executive',       'sequence' => 4, 'required_above_amount' => 200000],
        ]])->assertOk();

        $this->assertSame(3, TravelApprovalStage::count());
        $this->assertEquals(200000, (float) TravelApprovalStage::where('stage', 'executive')->first()->required_above_amount);
    }

    public function test_a_chain_where_every_stage_has_a_threshold_is_refused(): void
    {
        // A small request would then match no stage at all and be approved by
        // nobody.
        $this->actingAsWith([Permission::TRAVEL_MANAGE_RATES]);

        $this->putJson($this->url('/approval-stages'), ['stages' => [
            ['stage' => 'department_head', 'sequence' => 1, 'required_above_amount' => 1000],
            ['stage' => 'finance',         'sequence' => 3, 'required_above_amount' => 5000],
        ]])->assertStatus(422)->assertJsonValidationErrors('stages');
    }

    public function test_an_empty_chain_is_refused(): void
    {
        $this->actingAsWith([Permission::TRAVEL_MANAGE_RATES]);

        $this->putJson($this->url('/approval-stages'), ['stages' => []])
            ->assertStatus(422)->assertJsonValidationErrors('stages');
    }

    public function test_saving_the_chain_twice_updates_rather_than_duplicates(): void
    {
        $this->actingAsWith([Permission::TRAVEL_MANAGE_RATES]);

        $payload = ['stages' => [['stage' => 'department_head', 'sequence' => 1]]];

        $this->putJson($this->url('/approval-stages'), $payload)->assertOk();
        $this->putJson($this->url('/approval-stages'), $payload)->assertOk();

        $this->assertSame(1, TravelApprovalStage::count());
    }
}
