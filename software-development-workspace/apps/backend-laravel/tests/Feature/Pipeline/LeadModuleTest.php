<?php

namespace Tests\Feature\Pipeline;

use App\Models\Account\Account;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Pipeline\Lead;
use App\Models\Role;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use App\Support\Pipeline\LeadQualification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The lead board, its qualification, and the paperwork around it.
 *
 * What is worth fixing here is mostly about honesty on screen: a column that
 * says 10 while showing 3, a fit score of 90 that came from one answered
 * dimension out of five, and a percentile computed against leads nobody has
 * assessed. All three look like working software.
 */
class LeadModuleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('lead_documents');

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        DefaultReferenceData::seedFor($this->org->id);

        $this->account = Account::create([
            'organization_id' => $this->org->id, 'name' => 'Kenya Commercial Bank',
            'number_of_employees' => 300,
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

    private function url(string $path): string
    {
        return "/api/v1{$path}?org={$this->org->slug}";
    }

    private function lead(array $attrs = []): Lead
    {
        return Lead::create($attrs + [
            'organization_id' => $this->org->id,
            'account_id'      => $this->account->id,
            'name'            => 'Lead ' . fake()->unique()->numberBetween(1, 999999),
        ]);
    }

    // ── The board ─────────────────────────────────────────────────────────

    public function test_the_board_returns_a_column_for_each_status_even_when_empty(): void
    {
        // A missing column reads as a missing stage, not an empty one.
        $this->actor([Permission::PIPELINE_VIEW]);

        $columns = $this->getJson($this->url('/leads/board'))->assertOk()->json('data');

        $this->assertSame(
            ['new', 'contacted', 'qualified', 'unqualified'],
            array_column($columns, 'status'),
        );
    }

    public function test_a_column_count_is_the_number_of_leads_in_it_not_the_number_fetched(): void
    {
        // A board that says 10 while showing 3 is worse than no count at all.
        $this->actor([Permission::PIPELINE_VIEW]);

        foreach (range(1, 60) as $i) {
            $this->lead(['status' => 'new']);
        }

        $column = collect($this->getJson($this->url('/leads/board'))->assertOk()->json('data'))
            ->firstWhere('status', 'new');

        $this->assertSame(60, $column['total']);
        $this->assertCount(50, $column['leads']);
    }

    public function test_a_converted_lead_has_left_the_board(): void
    {
        $this->actor([Permission::PIPELINE_VIEW]);
        $this->lead(['status' => 'converted']);

        $columns = $this->getJson($this->url('/leads/board'))->assertOk()->json('data');

        $this->assertSame(0, collect($columns)->sum('total'));
    }

    public function test_a_card_can_be_dragged_to_another_column(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $lead = $this->lead(['status' => 'new', 'summary' => 'Confirm data integrity']);

        $res = $this->postJson($this->url("/leads/{$lead->id}/status"), ['status' => 'contacted'])->assertOk();

        $this->assertSame('contacted', $res->json('data.status'));
        // Moving a card must not blank the fields it did not send.
        $this->assertSame('Confirm data integrity', $res->json('data.summary'));
    }

    public function test_a_converted_lead_cannot_be_dragged_back_onto_the_board(): void
    {
        // Its opportunity already exists; putting it back would mean two
        // records claiming the same deal.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $lead = $this->lead(['status' => 'converted']);

        $this->postJson($this->url("/leads/{$lead->id}/status"), ['status' => 'new'])->assertStatus(422);
    }

    public function test_converted_is_not_a_column_a_card_can_be_dropped_into(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);

        $this->postJson($this->url("/leads/{$this->lead()->id}/status"), ['status' => 'converted'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    // ── Qualification ─────────────────────────────────────────────────────

    public function test_an_unassessed_lead_has_no_score_rather_than_a_score_of_zero(): void
    {
        // Zero would make every new lead look hopeless.
        $this->actor([Permission::PIPELINE_VIEW]);
        $lead = $this->lead();

        $res = $this->getJson($this->url("/leads/{$lead->id}"))->assertOk();

        $this->assertNull($res->json('data.qualification.score'));
        $this->assertNull($res->json('data.qualification.band'));
    }

    public function test_the_score_is_the_mean_of_the_dimensions_that_were_answered(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $lead = $this->lead();

        $res = $this->postJson($this->url("/leads/{$lead->id}/qualify"), [
            'fit_company' => 90, 'fit_budget' => 70, 'fit_solution' => 60,
            'fit_timeline' => 94, 'fit_stakeholder' => 84,
        ])->assertOk();

        // (90 + 70 + 60 + 94 + 84) / 5 = 79.6 → 80
        $this->assertSame(80, $res->json('data.qualification.score'));
        $this->assertSame('high_fit', $res->json('data.qualification.band'));
    }

    public function test_an_unanswered_dimension_is_skipped_rather_than_counted_as_zero(): void
    {
        // Otherwise one good score reads as a bad lead because nobody has
        // looked at the other four yet.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $lead = $this->lead();

        $res = $this->postJson($this->url("/leads/{$lead->id}/qualify"), ['fit_timeline' => 90])->assertOk();

        $this->assertSame(90, $res->json('data.qualification.score'));
    }

    public function test_the_score_is_reported_with_how_much_of_it_was_assessed(): void
    {
        // 90 from one dimension and 90 from five are not the same claim.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $lead = $this->lead();

        $res = $this->postJson($this->url("/leads/{$lead->id}/qualify"), ['fit_timeline' => 90])->assertOk();

        $this->assertSame(20, $res->json('data.qualification.completeness'));
    }

    public function test_a_grade_outside_nought_to_a_hundred_is_refused(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);

        $this->postJson($this->url("/leads/{$this->lead()->id}/qualify"), ['fit_company' => 140])
            ->assertStatus(422)->assertJsonValidationErrors('fit_company');
    }

    public function test_no_percentile_is_reported_until_there_is_something_to_compare_against(): void
    {
        // A percentile drawn from two leads is noise dressed as a statistic.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $lead = $this->lead(['fit_company' => 90]);

        $res = $this->getJson($this->url("/leads/{$lead->id}"))->assertOk();

        $this->assertNull($res->json('data.qualification.percentile'));
    }

    public function test_the_percentile_is_measured_against_assessed_leads_only(): void
    {
        // Ranking against leads nobody has looked at would flatter every one
        // of them.
        $this->actor([Permission::PIPELINE_VIEW]);

        foreach ([10, 20, 30, 40, 50, 60] as $score) {
            $this->lead(['fit_company' => $score]);
        }
        foreach (range(1, 20) as $i) {
            $this->lead();   // never assessed
        }

        $best = $this->lead(['fit_company' => 95]);

        $res = $this->getJson($this->url("/leads/{$best->id}"))->assertOk();

        // Nothing scores higher, so it is top 0% — not top 4% because twenty
        // blank leads were counted below it.
        $this->assertSame(0, $res->json('data.qualification.percentile'));
    }

    public function test_the_summary_judgements_are_recorded_beside_the_grades(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $lead = $this->lead();

        $res = $this->postJson($this->url("/leads/{$lead->id}/qualify"), [
            'budget_availability' => 'available', 'stakeholder_engagement' => 'high',
            'timeline_estimate' => '3–4 Months', 'technical_readiness' => 'medium',
            'decision_authority_access' => 'available',
        ])->assertOk();

        $this->assertSame('available', $res->json('data.qualification.summary.budget_availability'));
        $this->assertSame('3–4 Months', $res->json('data.qualification.summary.timeline_estimate'));
    }

    public function test_an_unrecognised_summary_judgement_is_refused(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);

        $this->postJson($this->url("/leads/{$this->lead()->id}/qualify"), ['stakeholder_engagement' => 'enormous'])
            ->assertStatus(422)->assertJsonValidationErrors('stakeholder_engagement');
    }

    public function test_qualifying_a_lead_needs_more_than_being_able_to_read_it(): void
    {
        $this->actor([Permission::PIPELINE_VIEW]);

        $this->postJson($this->url("/leads/{$this->lead()->id}/qualify"), ['fit_company' => 90])
            ->assertForbidden();
    }

    // ── Stakeholders ──────────────────────────────────────────────────────

    public function test_a_decision_maker_can_be_named_against_the_lead(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $lead = $this->lead();

        $res = $this->postJson($this->url("/leads/{$lead->id}/stakeholders"), [
            'name' => 'John Omondi', 'role' => 'Head of Digital Sales', 'influence' => 'decision_maker',
        ])->assertOk();

        $this->assertSame('Head of Digital Sales', $res->json('data.stakeholders.0.role'));
    }

    public function test_a_stakeholder_cannot_be_borrowed_from_another_customers_contacts(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);

        $other = Account::create(['organization_id' => $this->org->id, 'name' => 'Somebody Else']);
        $contact = $other->contacts()->create([
            'organization_id' => $this->org->id, 'first_name' => 'Not', 'last_name' => 'Ours',
        ]);

        $this->postJson($this->url("/leads/{$this->lead()->id}/stakeholders"), [
            'name' => 'Not Ours', 'account_contact_id' => $contact->id,
        ])->assertStatus(422)->assertJsonValidationErrors('account_contact_id');
    }

    public function test_a_stakeholder_of_another_lead_is_not_reachable(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $mine  = $this->lead();
        $other = $this->lead();

        $id = $this->postJson($this->url("/leads/{$other->id}/stakeholders"), ['name' => 'Theirs'])
            ->json('data.stakeholders.0.id');

        $this->deleteJson($this->url("/leads/{$mine->id}/stakeholders/{$id}"))->assertNotFound();
    }

    // ── Activities ────────────────────────────────────────────────────────

    public function test_what_happened_on_a_lead_is_kept_apart_from_the_customers_timeline(): void
    {
        // A lead going quiet while the customer is busy elsewhere is exactly
        // the case worth seeing.
        $this->actor([Permission::PIPELINE_VIEW]);
        $lead = $this->lead();

        $this->postJson($this->url("/leads/{$lead->id}/activities"), [
            'type' => 'meeting', 'body' => 'Stakeholder meeting conducted',
        ])->assertCreated();

        $this->assertSame(1, $lead->activities()->count());
        $this->assertSame(0, $this->account->activities()->count());
    }

    public function test_logging_what_happened_does_not_require_editing_the_lead(): void
    {
        // Anybody working it should be able to say a meeting took place.
        $this->actor([Permission::PIPELINE_VIEW]);

        $this->postJson($this->url("/leads/{$this->lead()->id}/activities"), ['body' => 'Called them'])
            ->assertCreated();
    }

    public function test_an_activity_cannot_be_dated_into_the_future(): void
    {
        $this->actor([Permission::PIPELINE_VIEW]);

        $this->postJson($this->url("/leads/{$this->lead()->id}/activities"), [
            'body' => 'Not yet', 'occurred_at' => now()->addWeek()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('occurred_at');
    }

    // ── Documents ─────────────────────────────────────────────────────────

    public function test_a_document_can_be_filed_against_a_lead(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $lead = $this->lead();

        $res = $this->post($this->url("/leads/{$lead->id}/attachments"), [
            'label' => 'AI Lead Scoring Requirements',
            'document_type' => 'client',
            'file' => UploadedFile::fake()->create('requirements.pdf', 200, 'application/pdf'),
        ])->assertOk();

        $this->assertSame('client', $res->json('data.attachments.0.document_type'));
    }

    public function test_a_document_takes_its_filename_when_no_name_is_given(): void
    {
        // Better than an untitled row nobody can identify.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $lead = $this->lead();

        $res = $this->post($this->url("/leads/{$lead->id}/attachments"), [
            'file' => UploadedFile::fake()->create('Budget Overview.xlsx', 300),
        ])->assertOk();

        $this->assertSame('Budget Overview.xlsx', $res->json('data.attachments.0.label'));
    }

    public function test_a_lead_document_is_not_reachable_without_seeing_the_lead(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $lead = $this->lead();

        $id = $this->post($this->url("/leads/{$lead->id}/attachments"), [
            'file' => UploadedFile::fake()->create('budget.pdf', 20, 'application/pdf'),
        ])->json('data.attachments.0.id');

        $this->get($this->url("/leads/{$lead->id}/attachments/{$id}/download"))->assertOk();

        // Somebody with no claim on the pipeline at all.
        $this->actor(['leave.view']);
        $this->get($this->url("/leads/{$lead->id}/attachments/{$id}/download"))->assertForbidden();
    }

    public function test_removing_a_document_keeps_the_record_that_it_existed(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $lead = $this->lead();

        $id = $this->post($this->url("/leads/{$lead->id}/attachments"), [
            'file' => UploadedFile::fake()->create('spec.pdf', 20, 'application/pdf'),
        ])->json('data.attachments.0.id');

        $this->deleteJson($this->url("/leads/{$lead->id}/attachments/{$id}"))->assertOk();

        $this->assertSame(0, $lead->attachments()->count());
        $this->assertSame(1, $lead->attachments()->withTrashed()->count());
    }

    // ── The company behind the lead ───────────────────────────────────────

    public function test_organisation_size_is_a_band_read_off_the_headcount(): void
    {
        // Two columns would let a customer be 300 employees and "1000+" at
        // the same time.
        $this->actor([Permission::ACCOUNTS_VIEW]);

        $res = $this->getJson($this->url("/accounts/{$this->account->id}"))->assertOk();

        $this->assertSame('250–500 Employees', $res->json('data.organization_size'));
    }

    public function test_a_customer_with_no_headcount_has_no_size_band(): void
    {
        $this->actor([Permission::ACCOUNTS_VIEW]);
        $account = Account::create(['organization_id' => $this->org->id, 'name' => 'Unknown Size']);

        $this->getJson($this->url("/accounts/{$account->id}"))
            ->assertOk()->assertJsonPath('data.organization_size', null);
    }

    // ── The score itself ──────────────────────────────────────────────────

    public function test_the_fit_bands_match_their_own_boundaries(): void
    {
        $this->assertSame('high_fit',     LeadQualification::band(80));
        $this->assertSame('moderate_fit', LeadQualification::band(79));
        $this->assertSame('moderate_fit', LeadQualification::band(60));
        $this->assertSame('low_fit',      LeadQualification::band(59));
        $this->assertSame('poor_fit',     LeadQualification::band(39));
        $this->assertNull(LeadQualification::band(null));
    }
}
