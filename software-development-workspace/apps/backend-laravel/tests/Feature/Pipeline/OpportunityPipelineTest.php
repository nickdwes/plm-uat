<?php

namespace Tests\Feature\Pipeline;

use App\Models\Account\Account;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Pipeline\Opportunity;
use App\Models\Pipeline\OpportunityPhase;
use App\Models\Role;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use App\Support\Pipeline\OpportunityScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The opportunity pipeline.
 *
 * The legacy enforced nothing: its fourteen phases were a dropdown, so a deal
 * could sit in Bidding with no bid and Contracting with no contract, and
 * frequently did. The gates here are few on purpose — a process that refuses
 * every step out of order gets worked around — so the ones that exist are
 * worth proving.
 *
 * The storage tests matter most. Plmv2 kept every pipeline file as a bare path
 * string on the record: no disk, no authorisation, no soft delete. These fix
 * that it is one private disk with one authorised download.
 */
class OpportunityPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('opportunity_documents');

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

    private function phase(string $name): OpportunityPhase
    {
        return OpportunityPhase::where('organization_id', $this->org->id)->where('name', $name)->firstOrFail();
    }

    private function deal(array $attrs = []): Opportunity
    {
        return Opportunity::create($attrs + [
            'organization_id' => $this->org->id,
            'account_id'      => $this->account->id,
            'name'            => 'Integrate AI lead scoring module',
            'opportunity_phase_id' => $this->phase('Opportunity Processing')->id,
        ]);
    }

    private function url(Opportunity $o, string $path = ''): string
    {
        return "/api/v1/opportunities/{$o->id}{$path}?org={$this->org->slug}";
    }

    /** Every indicator set, which is what the commit gate wants. */
    private function allIndicators(): array
    {
        return array_fill_keys(array_keys(OpportunityScore::INDICATORS), true);
    }

    // ── The strength score ────────────────────────────────────────────────

    public function test_the_score_is_ten_indicators_at_ten_points_each(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal();

        $res = $this->postJson($this->url($deal, '/indicators'), [
            'knows_procurement' => true, 'deal_registered' => true, 'compliance_pressure' => true,
        ])->assertOk();

        $this->assertSame(30, $res->json('data.strength.score'));
        $this->assertSame(100, $res->json('data.strength.max'));
        $this->assertSame(65, $res->json('data.strength.threshold'));
    }

    public function test_all_ten_indicators_are_the_legacys_own_booleans(): void
    {
        // Nothing invented, nothing dropped — only the weighting changed.
        $this->assertCount(10, OpportunityScore::INDICATORS);

        foreach (array_keys(OpportunityScore::INDICATORS) as $column) {
            $this->assertTrue(
                Opportunity::make()->isFillable($column),
                "{$column} should be a column on the opportunity, not an invention.",
            );
        }
    }

    public function test_the_pipeline_says_which_indicators_are_still_unmet(): void
    {
        // A score without the missing pieces tells nobody what to go and do.
        $this->actor([Permission::PIPELINE_VIEW]);
        $deal = $this->deal(['knows_procurement' => true]);

        $indicators = $this->getJson($this->url($deal, '/pipeline'))->assertOk()
            ->json('data.strength.indicators');

        $this->assertCount(10, $indicators);
        $this->assertSame(1, count(array_filter($indicators, fn ($i) => $i['met'])));
        // Every one carries its explanation, so the screen is not the only
        // place that knows what the indicator means.
        $this->assertNotEmpty($indicators[0]['help']);
    }

    // ── Stage gates ───────────────────────────────────────────────────────

    public function test_a_deal_below_the_threshold_cannot_be_committed(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal(['knows_procurement' => true, 'deal_registered' => true]);

        $this->postJson($this->url($deal, "/stages/{$this->phase('Opportunity Commit')->id}/complete"))
            ->assertStatus(422)->assertJsonValidationErrors('stage');
    }

    public function test_a_deal_at_the_threshold_can_be_committed(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal(array_slice($this->allIndicators(), 0, 7));   // 70 points

        $this->postJson($this->url($deal, "/stages/{$this->phase('Opportunity Commit')->id}/complete"))
            ->assertOk();
    }

    public function test_bidding_cannot_be_closed_without_a_bid(): void
    {
        // The legacy had deals sitting in Bidding with nothing recorded.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);

        $this->postJson($this->url($this->deal(), "/stages/{$this->phase('Bidding')->id}/complete"))
            ->assertStatus(422)->assertJsonValidationErrors('stage');
    }

    public function test_contracting_cannot_be_closed_without_a_contract(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);

        $this->postJson($this->url($this->deal(), "/stages/{$this->phase('Contracting')->id}/complete"))
            ->assertStatus(422)->assertJsonValidationErrors('stage');
    }

    public function test_kickoff_cannot_be_closed_without_a_project(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);

        $this->postJson($this->url($this->deal(), "/stages/{$this->phase('Project Kick-Off')->id}/complete"))
            ->assertStatus(422)->assertJsonValidationErrors('stage');
    }

    public function test_an_ungated_stage_closes_on_judgement_alone(): void
    {
        // Most of them. A process that refuses every step gets worked around.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal();

        $res = $this->postJson($this->url($deal, "/stages/{$this->phase('Scope/Site Survey')->id}/complete"))
            ->assertOk();

        $survey = collect($res->json('data.stages'))->firstWhere('name', 'Scope/Site Survey');
        $this->assertNotNull($survey['completed_at']);
    }

    public function test_completing_a_stage_twice_keeps_the_first_timestamp(): void
    {
        // When a thing was first done is the useful fact.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal  = $this->deal();
        $phase = $this->phase('Scope/Site Survey');

        $first = $this->postJson($this->url($deal, "/stages/{$phase->id}/complete"))->assertOk()
            ->json('data.stages');
        $when = collect($first)->firstWhere('id', $phase->id)['completed_at'];

        $again = $this->postJson($this->url($deal, "/stages/{$phase->id}/complete"))->assertOk()
            ->json('data.stages');

        $this->assertSame($when, collect($again)->firstWhere('id', $phase->id)['completed_at']);
    }

    public function test_completing_an_earlier_stage_does_not_drag_the_deal_backwards(): void
    {
        // Somebody ticking off a stage they went back to fix should not undo
        // the deal's progress.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal(['opportunity_phase_id' => $this->phase('Bidding')->id]);

        $this->postJson($this->url($deal, "/stages/{$this->phase('Estimate')->id}/complete"))->assertOk();

        $this->assertSame($this->phase('Bidding')->id, $deal->fresh()->opportunity_phase_id);
    }

    public function test_a_stage_can_be_reopened(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal  = $this->deal();
        $phase = $this->phase('Scope/Site Survey');

        $this->postJson($this->url($deal, "/stages/{$phase->id}/complete"))->assertOk();
        $res = $this->postJson($this->url($deal, "/stages/{$phase->id}/reopen"))->assertOk();

        $this->assertNull(collect($res->json('data.stages'))->firstWhere('id', $phase->id)['completed_at']);
    }

    // ── Qualification ─────────────────────────────────────────────────────

    public function test_a_deal_cannot_be_marked_qualified_against_nothing(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);

        $this->postJson($this->url($this->deal(), '/mark-qualified'))
            ->assertStatus(422)->assertJsonValidationErrors('qualification');
    }

    public function test_a_deal_can_be_marked_qualified_once_assessed(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal();

        $this->postJson($this->url($deal, '/qualify'), [
            'fit_company' => 90, 'fit_budget' => 70, 'fit_solution' => 60,
            'fit_timeline' => 94, 'fit_stakeholder' => 84,
        ])->assertOk();

        $res = $this->postJson($this->url($deal, '/mark-qualified'))->assertOk();

        $this->assertSame(80, $res->json('data.qualification.score'));
        $this->assertNotNull($res->json('data.qualification.qualified_at'));
    }

    // ── Budget confirmation ───────────────────────────────────────────────

    public function test_confirming_a_budget_records_that_it_stands(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal();

        $res = $this->postJson($this->url($deal, '/budget/confirm'), ['amount' => 30000])->assertOk();

        $this->assertTrue($res->json('data.budget.is_confirmed'));
        $this->assertEquals(30000, $res->json('data.budget.amount'));
    }

    public function test_revoking_a_budget_keeps_the_figure_that_was_quoted(): void
    {
        // Withdrawn is not the same as never quoted.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal();

        $this->postJson($this->url($deal, '/budget/confirm'), ['amount' => 30000])->assertOk();
        $res = $this->postJson($this->url($deal, '/budget/revoke'))->assertOk();

        $this->assertFalse($res->json('data.budget.is_confirmed'));
        $this->assertEquals(30000, $res->json('data.budget.amount'));
    }

    // ── Estimates ─────────────────────────────────────────────────────────

    public function test_a_quoted_profit_is_kept_as_quoted(): void
    {
        // Computing it would silently rewrite what somebody agreed with a
        // customer. The arithmetic is reported alongside instead.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal();

        $res = $this->postJson($this->url($deal, '/estimates'), [
            'name' => 'Phase 1 - Network', 'budget' => 30000,
            'revenue' => 30000, 'cost' => 20000, 'profit' => 8000,
        ])->assertOk();

        $estimate = $res->json('data.estimates.0');
        $this->assertEquals(8000, $estimate['profit']);
        $this->assertEquals(10000, $estimate['implied_profit']);
    }

    // ── Commit objectives ─────────────────────────────────────────────────

    public function test_an_objective_carries_the_quarter_span_the_screen_shows(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal();

        $res = $this->postJson($this->url($deal, '/objectives'), [
            'name' => 'Phase 1',
            'expected_start_date' => '2026-03-28', 'expected_end_date' => '2026-08-12',
        ])->assertOk();

        $this->assertSame('Q1 – Q3', $res->json('data.objectives.0.quarter_span'));
    }

    public function test_an_objective_cannot_end_before_it_starts(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);

        $this->postJson($this->url($this->deal(), '/objectives'), [
            'name' => 'Backwards',
            'expected_start_date' => '2026-08-12', 'expected_end_date' => '2026-03-28',
        ])->assertStatus(422)->assertJsonValidationErrors('expected_end_date');
    }

    // ── Bids ──────────────────────────────────────────────────────────────

    public function test_a_bid_reports_what_we_stand_to_make(): void
    {
        // Unlike an estimate's profit this one is not negotiated — it is what
        // we bid less what it costs us — so it is derived.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal();

        $res = $this->postJson($this->url($deal, '/bids'), [
            'tender_number' => '#TR678932HG', 'bid_number' => 'BID-001',
            'bidding_value' => 30000, 'bidding_cost' => 10000,
        ])->assertOk();

        $this->assertEquals(20000, $res->json('data.bids.0.bidding_profit'));
    }

    public function test_a_bond_lapsing_before_the_award_is_flagged(): void
    {
        // A bid that cannot be honoured, which nothing else would surface.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal();

        $res = $this->postJson($this->url($deal, '/bids'), [
            'tender_number' => '#TR678932HG',
            'bid_bond_validity_date' => '2026-04-01', 'expected_award_date' => '2026-06-20',
        ])->assertOk();

        $this->assertTrue($res->json('data.bids.0.bond_lapses_before_award'));
    }

    public function test_a_removed_bid_is_kept(): void
    {
        // A withdrawn bid is still one that was submitted, and the bond that
        // went with it was still lodged.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal();

        $id = $this->postJson($this->url($deal, '/bids'), ['tender_number' => '#TR1'])
            ->json('data.bids.0.id');

        $this->deleteJson($this->url($deal, "/bids/{$id}"))->assertOk();

        $this->assertSame(0, $deal->bids()->count());
        $this->assertSame(1, $deal->bids()->withTrashed()->count());
    }

    // ── Contracting and kickoff ───────────────────────────────────────────

    public function test_the_contract_a_deal_produces_is_the_customers_contract(): void
    {
        // The legacy and Plmv2 both kept a second copy on the opportunity, and
        // the two drifted.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE, Permission::ACCOUNTS_VIEW]);
        $deal = $this->deal();

        $this->postJson($this->url($deal, '/contracts'), [
            'name' => 'Enterprise SaaS Agreement',
            'start_date' => '2026-02-01', 'end_date' => '2027-01-31',
            'contract_value' => 1200000, 'billing_cycle' => 'annual',
        ])->assertOk();

        $this->assertSame(1, $this->account->contracts()->count());
        $this->assertSame($deal->id, $this->account->contracts()->first()->opportunity_id);
    }

    public function test_a_project_kickoff_records_the_project_against_the_customer_too(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal();

        $res = $this->postJson($this->url($deal, '/projects'), [
            'name' => 'Employee Portal Revamp', 'priority' => 'high', 'deadline' => '2026-02-28',
        ])->assertOk();

        $this->assertMatchesRegularExpression('/^PR-\d+$/', $res->json('data.projects.0.reference'));
        $this->assertSame($this->account->id, $deal->projects()->first()->account_id);
    }

    // ── Documents: the storage guarantees ─────────────────────────────────

    public function test_every_stage_files_through_one_upload_path(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal();

        foreach (['site_survey', 'specification', 'negotiation', 'proposal', 'general'] as $stage) {
            $this->post($this->url($deal, '/documents'), [
                'stage' => $stage,
                'file'  => UploadedFile::fake()->create("{$stage}.pdf", 40, 'application/pdf'),
            ])->assertOk();
        }

        $this->assertSame(5, $deal->documents()->count());
    }

    public function test_a_pipeline_document_is_never_publicly_addressable(): void
    {
        // A tender response is commercially damaging before an award. Plmv2
        // kept these as bare path strings with nothing in front of them.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal();

        $res = $this->post($this->url($deal, '/documents'), [
            'stage' => 'bid', 'document_type' => 'financial',
            'file'  => UploadedFile::fake()->create('tender-response.pdf', 40, 'application/pdf'),
        ])->assertOk();

        $doc = $res->json('data.documents.bid.0');

        // No URL is ever handed out — only an id for the authorised route.
        $this->assertArrayNotHasKey('url', $doc);
        $this->assertArrayNotHasKey('storage_path', $doc);

        $this->get($this->url($deal, "/documents/{$doc['id']}/download"))->assertOk();

        // Somebody with no claim on the pipeline at all.
        $this->actor(['leave.view']);
        $this->get($this->url($deal, "/documents/{$doc['id']}/download"))->assertForbidden();
    }

    public function test_a_document_cannot_be_filed_against_another_deals_bid(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $mine  = $this->deal();
        $other = $this->deal();

        $theirBid = $this->postJson($this->url($other, '/bids'), ['tender_number' => '#TR-THEIRS'])
            ->json('data.bids.0.id');

        $this->post($this->url($mine, '/documents'), [
            'stage' => 'bid', 'bid_id' => $theirBid,
            'file'  => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('bid_id');
    }

    public function test_an_unknown_stage_is_refused(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);

        $this->post($this->url($this->deal(), '/documents'), [
            'stage' => 'whenever',
            'file'  => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('stage');
    }

    public function test_removing_a_document_keeps_what_was_submitted(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $deal = $this->deal();

        $id = $this->post($this->url($deal, '/documents'), [
            'stage' => 'bid',
            'file'  => UploadedFile::fake()->create('response.pdf', 10, 'application/pdf'),
        ])->json('data.documents.bid.0.id');

        $this->deleteJson($this->url($deal, "/documents/{$id}"))->assertOk();

        $this->assertSame(0, $deal->documents()->count());
        $this->assertSame(1, $deal->documents()->withTrashed()->count());
    }

    public function test_the_pipeline_is_not_readable_without_seeing_the_deal(): void
    {
        $deal = $this->deal();
        $this->actor(['leave.view']);

        $this->getJson($this->url($deal, '/pipeline'))->assertForbidden();
    }
}
