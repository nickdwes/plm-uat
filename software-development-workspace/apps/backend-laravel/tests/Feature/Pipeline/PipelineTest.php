<?php

namespace Tests\Feature\Pipeline;

use App\Models\Account\Account;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Pipeline\Lead;
use App\Models\Pipeline\Opportunity;
use App\Models\Pipeline\OpportunityPhase;
use App\Models\Role;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use App\Support\Pipeline\OpportunityScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Leads and the deals they become.
 *
 * The properties worth fixing are the ones the legacy got wrong and nobody
 * could see: a status that secretly meant "converted" so open leads could not
 * be counted; an opportunity status that disagreed with the phase on every
 * row; and a score whose quarter arithmetic used two different bases, so a
 * deal closing this quarter usually scored as though it closed next.
 */
class PipelineTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

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

        $employee = $this->employee();
        $employee->update(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function employee(): Employee
    {
        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Test', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function url(string $path): string
    {
        return "/api/v1{$path}?org={$this->org->slug}";
    }

    private function phase(string $name): OpportunityPhase
    {
        return OpportunityPhase::where('organization_id', $this->org->id)->where('name', $name)->firstOrFail();
    }

    private function lead(array $attrs = []): Lead
    {
        return Lead::create($attrs + [
            'organization_id' => $this->org->id,
            'account_id'      => $this->account->id,
            'name'            => 'Integrate AI lead scoring module',
        ]);
    }

    private function opportunity(array $attrs = []): Opportunity
    {
        return Opportunity::create($attrs + [
            'organization_id'      => $this->org->id,
            'account_id'           => $this->account->id,
            'name'                 => 'CRM Implementation',
            'opportunity_phase_id' => $this->phase('Bidding')->id,
        ]);
    }

    // ── The sales process ─────────────────────────────────────────────────

    public function test_an_organisation_starts_with_the_sales_process_seeded(): void
    {
        $this->actor([Permission::PIPELINE_VIEW]);

        $phases = $this->getJson($this->url('/opportunities/phases'))->assertOk()->json('data');

        $this->assertCount(14, $phases);
        $this->assertSame('Opportunity Processing', $phases[0]['name']);
    }

    public function test_only_win_and_lost_close_a_deal(): void
    {
        // The outcome is what makes those two different from the twelve
        // phases that precede them.
        $closing = OpportunityPhase::where('organization_id', $this->org->id)
            ->whereNotNull('outcome')->pluck('name')->sort()->values()->all();

        $this->assertSame(['Lost', 'Win'], $closing);
    }

    // ── Leads ─────────────────────────────────────────────────────────────

    public function test_a_lead_is_given_a_reference(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);

        $this->postJson($this->url('/leads'), ['name' => 'Cloud storage refresh'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'new');
    }

    public function test_a_lead_can_exist_before_the_customer_is_on_file(): void
    {
        // Leads arrive from people nobody has opened a record for yet.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);

        $this->postJson($this->url('/leads'), ['name' => 'Inbound enquiry'])
            ->assertCreated()->assertJsonPath('data.account', null);
    }

    public function test_open_leads_can_be_counted_without_knowing_which_statuses_mean_converted(): void
    {
        // The legacy stored "Attached to an opportunity" as a status, so this
        // question needed a list of which values secretly meant converted.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);

        $this->lead(['status' => 'new']);
        $this->lead(['status' => 'contacted']);
        $this->lead(['status' => 'converted']);
        $this->lead(['status' => 'lost']);

        $open = $this->getJson($this->url('/leads') . '&status=open')->assertOk()->json('data');

        $this->assertCount(2, $open);
    }

    public function test_a_lead_raised_against_a_customer_lands_on_their_timeline(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);

        $this->postJson($this->url('/leads'), [
            'name' => 'Cloud storage refresh', 'account_id' => $this->account->id,
        ])->assertCreated();

        $this->assertSame(1, $this->account->activities()->where('type', 'lead')->count());
    }

    public function test_opening_a_lead_is_not_counted_as_having_spoken_to_them(): void
    {
        // It is a system entry, so it must not move last contact.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);

        $this->postJson($this->url('/leads'), [
            'name' => 'Cloud storage refresh', 'account_id' => $this->account->id,
        ])->assertCreated();

        $this->assertNull($this->account->fresh()->last_contact_at);
    }

    public function test_a_lead_cannot_be_raised_against_another_tenants_customer(): void
    {
        $beta  = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $their = Account::withoutGlobalScopes()->create(['organization_id' => $beta->id, 'name' => 'Theirs']);

        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);

        $this->postJson($this->url('/leads'), ['name' => 'Poached', 'account_id' => $their->id])
            ->assertStatus(422)->assertJsonValidationErrors('account_id');
    }

    // ── Conversion ────────────────────────────────────────────────────────

    public function test_converting_a_lead_records_the_link_rather_than_writing_it_into_the_status(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);
        $lead = $this->lead();

        $res = $this->postJson($this->url("/leads/{$lead->id}/convert"), [
            'name' => 'CRM Implementation', 'account_id' => $this->account->id, 'amount' => 1500000,
        ])->assertCreated();

        $this->assertSame($lead->id, $res->json('data.lead.id'));
        $this->assertSame('converted', $lead->fresh()->status);
    }

    public function test_a_lead_cannot_be_converted_twice(): void
    {
        // Two deals from one lead would double-count the same revenue.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);
        $lead = $this->lead();

        $body = ['name' => 'CRM Implementation', 'account_id' => $this->account->id];

        $this->postJson($this->url("/leads/{$lead->id}/convert"), $body)->assertCreated();
        $this->postJson($this->url("/leads/{$lead->id}/convert"), $body)
            ->assertStatus(422)->assertJsonValidationErrors('lead');
    }

    public function test_a_deal_cannot_be_opened_against_nobody(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);
        $lead = $this->lead(['account_id' => null]);

        $this->postJson($this->url("/leads/{$lead->id}/convert"), ['name' => 'Orphan deal'])
            ->assertStatus(422)->assertJsonValidationErrors('account_id');
    }

    public function test_a_converted_lead_starts_the_deal_in_the_first_phase(): void
    {
        // A null phase is neither open nor closed, which makes the pipeline
        // unanswerable.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);

        $res = $this->postJson($this->url("/leads/{$this->lead()->id}/convert"), [
            'name' => 'CRM Implementation', 'account_id' => $this->account->id,
        ])->assertCreated();

        $this->assertSame('Opportunity Processing', $res->json('data.phase.name'));
        $this->assertTrue($res->json('data.is_open'));
    }

    // ── Outcome comes from the phase ──────────────────────────────────────

    public function test_whether_a_deal_is_won_is_read_from_its_phase(): void
    {
        // The legacy stored a status beside the phase and the two disagreed on
        // all 130 rows.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CLOSE]);
        $opportunity = $this->opportunity();

        $res = $this->postJson($this->url("/opportunities/{$opportunity->id}/phase"), [
            'opportunity_phase_id' => $this->phase('Win')->id,
        ])->assertOk();

        $this->assertSame('won', $res->json('data.outcome'));
        $this->assertFalse($res->json('data.is_open'));
        $this->assertNotNull($res->json('data.closed_at'));
    }

    public function test_moving_a_deal_back_out_of_a_closing_phase_reopens_it(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CLOSE, Permission::PIPELINE_UPDATE]);
        $opportunity = $this->opportunity();

        $this->postJson($this->url("/opportunities/{$opportunity->id}/phase"), [
            'opportunity_phase_id' => $this->phase('Win')->id,
        ])->assertOk();

        $res = $this->postJson($this->url("/opportunities/{$opportunity->id}/phase"), [
            'opportunity_phase_id' => $this->phase('Negotiations')->id,
        ])->assertOk();

        $this->assertTrue($res->json('data.is_open'));
        $this->assertNull($res->json('data.closed_at'));
    }

    public function test_closing_a_deal_needs_more_than_the_right_to_edit_it(): void
    {
        // The won figure is what gets reported upwards; correcting a typo and
        // booking revenue should not be the same authority.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);

        $this->postJson($this->url("/opportunities/{$this->opportunity()->id}/phase"), [
            'opportunity_phase_id' => $this->phase('Win')->id,
        ])->assertForbidden();
    }

    public function test_a_deal_cannot_be_closed_through_a_plain_edit(): void
    {
        // Otherwise the close permission is decoration.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $opportunity = $this->opportunity();

        $res = $this->putJson($this->url("/opportunities/{$opportunity->id}"), [
            'name' => 'CRM Implementation', 'account_id' => $this->account->id,
            'opportunity_phase_id' => $this->phase('Win')->id,
        ])->assertOk();

        $this->assertTrue($res->json('data.is_open'));
    }

    // ── Score ─────────────────────────────────────────────────────────────

    public function test_a_deal_closing_this_quarter_scores_the_full_timing_weight(): void
    {
        // The legacy computed the current quarter as ceil((month - 1) / 3) and
        // the closing one as ceil(month / 3) — two bases, so a deal closing
        // this quarter usually scored as though it closed next.
        $asOf = Carbon::create(2026, 2, 15);
        $o = $this->opportunity(['expected_close_date' => '2026-03-31']);

        $this->assertSame(15, OpportunityScore::for($o, $asOf));
    }

    public function test_timing_weight_falls_off_the_further_out_the_close_is(): void
    {
        $asOf = Carbon::create(2026, 2, 15);

        $this->assertSame(10, OpportunityScore::for($this->opportunity(['expected_close_date' => '2026-05-01']), $asOf));
        $this->assertSame(5,  OpportunityScore::for($this->opportunity(['expected_close_date' => '2026-08-01']), $asOf));
        $this->assertSame(0,  OpportunityScore::for($this->opportunity(['expected_close_date' => '2026-12-01']), $asOf));
    }

    public function test_two_quarters_out_scores_five_not_ten(): void
    {
        // The legacy's branch for this had no break and fell through, quietly
        // awarding double what its own table said.
        $asOf = Carbon::create(2026, 2, 15);

        $this->assertSame(5, OpportunityScore::for($this->opportunity(['expected_close_date' => '2026-09-30']), $asOf));
    }

    public function test_a_deal_already_past_its_close_date_earns_nothing_for_timing(): void
    {
        // It is late, not imminent.
        $asOf = Carbon::create(2026, 6, 1);

        $this->assertSame(0, OpportunityScore::for($this->opportunity(['expected_close_date' => '2026-01-01']), $asOf));
    }

    public function test_the_qualification_weights_are_the_legacy_ones(): void
    {
        $asOf = Carbon::create(2026, 2, 15);

        $o = $this->opportunity([
            'expected_close_date' => '2026-03-31',   // 15
            'account_weight'      => 'category_1',   // 15
            'amount'              => 25000000,       //  5
            'knows_procurement'   => true,           // 10
            'deal_registered'     => true,           //  5
            'compliance_pressure' => true,           // 15
            'oem_pressure'        => true,           //  5
            'prior_deal_closed'   => true,           // 10
        ]);

        $this->assertSame(80, OpportunityScore::for($o, $asOf));
        $this->assertSame(OpportunityScore::MAX, OpportunityScore::for($o, $asOf));
    }

    public function test_the_score_band_matches_its_own_boundaries(): void
    {
        // The legacy's banding compared the score against a boolean, so every
        // band was decided by a type juggle, and its label said 30–49 while
        // its code tested 30–59.
        $this->assertSame('weak',       OpportunityScore::band(29));
        $this->assertSame('developing', OpportunityScore::band(30));
        $this->assertSame('developing', OpportunityScore::band(49));
        $this->assertSame('promising',  OpportunityScore::band(50));
        $this->assertSame('promising',  OpportunityScore::band(64));
        $this->assertSame('strong',     OpportunityScore::band(65));
    }

    // ── Commitment ────────────────────────────────────────────────────────

    public function test_a_deal_cannot_be_committed_to_no_particular_quarter(): void
    {
        // The legacy allowed it: the flag was set on 38 rows but only 33
        // carried a quarter, so five commitments could not be placed.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);

        $this->postJson($this->url('/opportunities'), [
            'name' => 'CRM Implementation', 'account_id' => $this->account->id, 'is_committed' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('committed_quarter');
    }

    // ── Visibility ────────────────────────────────────────────────────────

    public function test_somebody_with_only_view_own_sees_their_own_deals(): void
    {
        $me = $this->actor([Permission::PIPELINE_VIEW_OWN]);

        $this->opportunity(['owner_id' => $me->id]);
        $this->opportunity(['owner_id' => $this->employee()->id]);

        $this->getJson($this->url('/opportunities'))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_researcher_can_open_the_lead_they_were_asked_to_research(): void
    {
        // Being assigned to research something you cannot open would be a
        // strange arrangement.
        $me   = $this->actor([Permission::PIPELINE_VIEW_OWN]);
        $lead = $this->lead(['owner_id' => $this->employee()->id]);
        $lead->team()->create(['organization_id' => $this->org->id, 'employee_id' => $me->id]);

        $this->getJson($this->url("/leads/{$lead->id}"))->assertOk();
    }

    public function test_a_lead_that_is_neither_yours_nor_your_research_is_not_visible(): void
    {
        $this->actor([Permission::PIPELINE_VIEW_OWN]);
        $lead = $this->lead(['owner_id' => $this->employee()->id]);

        $this->getJson($this->url("/leads/{$lead->id}"))->assertForbidden();
    }

    // ── The account summary ───────────────────────────────────────────────

    public function test_the_customer_summary_counts_deals_rather_than_storing_them(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW, Permission::PIPELINE_VIEW]);

        $this->opportunity(['amount' => 1000000, 'opportunity_phase_id' => $this->phase('Win')->id]);
        $this->opportunity(['amount' => 500000,  'opportunity_phase_id' => $this->phase('Lost')->id]);
        $this->opportunity(['amount' => 250000,  'opportunity_phase_id' => $this->phase('Bidding')->id]);

        $summary = $this->getJson($this->url("/accounts/{$this->account->id}/summary"))->assertOk()->json('data');

        $this->assertSame(3, $summary['total_deals']);
        $this->assertSame(1, $summary['active_deals']);
        $this->assertSame(1, $summary['closed_won']);
        $this->assertEquals(1000000, $summary['won_value']);
        // Kept apart from won so a forecast is never mistaken for revenue.
        $this->assertEquals(250000, $summary['pipeline_value']);
    }
}
