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

        // No 'qualified': qualifying a lead converts it, so it leaves the
        // board in the same gesture rather than resting in a column.
        $this->assertSame(
            ['new', 'contacted', 'unqualified'],
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

    public function test_qualified_is_not_a_column_a_card_can_be_dropped_into_either(): void
    {
        // Qualifying is an act that opens a deal, not a place to park a lead.
        // Dragging a card should never bring an opportunity into existence.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);

        $this->postJson($this->url("/leads/{$this->lead()->id}/status"), ['status' => 'qualified'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    // ── Qualifying is becoming a deal ─────────────────────────────────────

    public function test_qualifying_a_lead_opens_it_as_a_deal(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);
        $lead = $this->lead(['name' => 'FTTX domain servers']);

        $res = $this->postJson($this->url("/leads/{$lead->id}/convert"), [])->assertStatus(201);

        $this->assertSame('FTTX domain servers', $res->json('data.name'));
        $this->assertSame($lead->id, $res->json('data.lead.id'));
        $this->assertSame('converted', $lead->fresh()->status);
    }

    public function test_qualifying_needs_no_form_because_the_lead_already_said_it(): void
    {
        // The customer and the name are on the lead. Asking for them again,
        // underneath themselves, is what a one-gesture act must not do.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);
        $lead = $this->lead();

        $res = $this->postJson($this->url("/leads/{$lead->id}/convert"), [])->assertStatus(201);

        $this->assertSame($lead->account_id, $res->json('data.account.id'));
    }

    public function test_the_assessment_travels_onto_the_deal(): void
    {
        // Nobody should answer five questions twice about the same customer
        // on the same day.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE, Permission::PIPELINE_CREATE]);
        $lead = $this->lead();

        $this->postJson($this->url("/leads/{$lead->id}/qualify"), [
            'fit_company' => 'core', 'fit_budget' => 'indicated',
        ])->assertOk();

        $id = $this->postJson($this->url("/leads/{$lead->id}/convert"), [])
            ->assertStatus(201)->json('data.id');

        $deal = \App\Models\Pipeline\Opportunity::findOrFail($id);

        $this->assertSame(100, $deal->fit_company);
        $this->assertSame(60, $deal->fit_budget);
        $this->assertNull($deal->fit_solution);
    }

    public function test_a_lead_with_no_customer_cannot_qualify(): void
    {
        // A deal has to be with somebody — and the refusal has to say so in
        // words, not name a field the person never saw.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);
        $lead = $this->lead(['account_id' => null]);

        $res = $this->postJson($this->url("/leads/{$lead->id}/convert"), [])
            ->assertStatus(422)->assertJsonValidationErrors('account_id');

        $this->assertStringContainsString('Name the customer', $res->json('errors.account_id.0'));
    }

    public function test_a_lead_qualifies_only_once(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_CREATE]);
        $lead = $this->lead();

        $this->postJson($this->url("/leads/{$lead->id}/convert"), [])->assertStatus(201);
        $this->postJson($this->url("/leads/{$lead->id}/convert"), [])->assertStatus(422);
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

    public function test_the_score_is_the_mean_of_the_questions_that_were_answered(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $lead = $this->lead();

        $res = $this->postJson($this->url("/leads/{$lead->id}/qualify"), [
            'fit_company'     => 'core',          // 100
            'fit_budget'      => 'indicated',     //  60
            'fit_solution'    => 'configured',    //  70
            'fit_timeline'    => 'this_quarter',  // 100
            'fit_stakeholder' => 'sponsored',     //  70
        ])->assertOk();

        // 400 / 5 = 80
        $this->assertSame(80, $res->json('data.qualification.score'));
        $this->assertSame('high_fit', $res->json('data.qualification.band'));
    }

    public function test_an_answer_is_stored_as_what_it_is_worth(): void
    {
        // The column stays an integer, so the arithmetic, the bands and the
        // percentile all carry on working — only the way it is asked changed.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $lead = $this->lead();

        $this->postJson($this->url("/leads/{$lead->id}/qualify"), ['fit_budget' => 'allocated'])
            ->assertOk();

        $this->assertSame(100, $lead->fresh()->fit_budget);
    }

    public function test_the_answer_reads_back_as_the_sentence_that_was_chosen(): void
    {
        // A stored 60 has to be legible as "Indicated, not allocated", or the
        // screen is showing a number nobody typed.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $lead = $this->lead();

        $res = $this->postJson($this->url("/leads/{$lead->id}/qualify"), ['fit_budget' => 'indicated'])
            ->assertOk();

        $budget = collect($res->json('data.qualification.dimensions'))->firstWhere('key', 'fit_budget');

        $this->assertSame('indicated', $budget['answer']['value']);
        $this->assertSame('Indicated, not allocated', $budget['answer']['label']);
        $this->assertSame(60, $budget['value']);
    }

    public function test_the_answers_on_offer_ship_with_the_lead(): void
    {
        // So the wording on screen is the wording the server validates.
        $this->actor([Permission::PIPELINE_VIEW]);

        $res = $this->getJson($this->url("/leads/{$this->lead()->id}"))->assertOk();

        $company = collect($res->json('data.qualification.dimensions'))->firstWhere('key', 'fit_company');

        $this->assertSame('How well does this customer fit the business we want?', $company['question']);
        $this->assertSame(['core', 'adjacent', 'stretch', 'outside'], array_column($company['options'], 'value'));
    }

    public function test_an_unanswered_question_is_skipped_rather_than_counted_as_zero(): void
    {
        // Otherwise one good answer reads as a bad lead because nobody has
        // put the other four questions yet.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $lead = $this->lead();

        $res = $this->postJson($this->url("/leads/{$lead->id}/qualify"), ['fit_timeline' => 'next_quarter'])
            ->assertOk();

        $this->assertSame(70, $res->json('data.qualification.score'));
    }

    public function test_the_score_is_reported_with_how_much_of_it_was_assessed(): void
    {
        // One answer and five answers are not the same claim.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $lead = $this->lead();

        $res = $this->postJson($this->url("/leads/{$lead->id}/qualify"), ['fit_timeline' => 'next_quarter'])
            ->assertOk();

        $this->assertSame(20, $res->json('data.qualification.completeness'));
        $this->assertCount(4, $res->json('data.qualification.unanswered'));
    }

    public function test_answering_one_question_leaves_the_others_alone(): void
    {
        // The old form saved all five at once, so revisiting it to change one
        // answer meant re-stating four. Each question stands on its own now.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $lead = $this->lead();

        $this->postJson($this->url("/leads/{$lead->id}/qualify"), ['fit_company' => 'core'])->assertOk();
        $this->postJson($this->url("/leads/{$lead->id}/qualify"), ['fit_budget' => 'none'])->assertOk();

        $lead->refresh();

        $this->assertSame(100, $lead->fit_company);
        $this->assertSame(0, $lead->fit_budget);
    }

    public function test_an_answer_can_be_taken_back(): void
    {
        // Distinct from answering "none", which is itself a finding worth zero.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $lead = $this->lead(['fit_company' => 100]);

        $this->postJson($this->url("/leads/{$lead->id}/qualify"), ['fit_company' => null])->assertOk();

        $this->assertNull($lead->fresh()->fit_company);
    }

    public function test_an_answer_we_do_not_offer_is_refused(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);

        $this->postJson($this->url("/leads/{$this->lead()->id}/qualify"), ['fit_company' => 'magnificent'])
            ->assertStatus(422)->assertJsonValidationErrors('fit_company');
    }

    public function test_an_answer_belonging_to_another_question_is_refused(): void
    {
        // 'allocated' is a budget answer; it means nothing about the timeline.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);

        $this->postJson($this->url("/leads/{$this->lead()->id}/qualify"), ['fit_timeline' => 'allocated'])
            ->assertStatus(422)->assertJsonValidationErrors('fit_timeline');
    }

    public function test_a_raw_number_is_no_longer_accepted(): void
    {
        // The whole point of the change: nobody can defend 70 over 75.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);

        $this->postJson($this->url("/leads/{$this->lead()->id}/qualify"), ['fit_company' => 90])
            ->assertStatus(422)->assertJsonValidationErrors('fit_company');
    }

    public function test_no_percentile_is_reported_until_there_is_something_to_compare_against(): void
    {
        // A percentile drawn from two leads is noise dressed as a statistic.
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);
        $lead = $this->lead(['fit_company' => 100]);

        $res = $this->getJson($this->url("/leads/{$lead->id}"))->assertOk();

        $this->assertNull($res->json('data.qualification.percentile'));
    }

    public function test_the_percentile_is_measured_against_assessed_leads_only(): void
    {
        // Ranking against leads nobody has looked at would flatter every one
        // of them.
        $this->actor([Permission::PIPELINE_VIEW]);

        // Six assessed leads, each on a real answer, with six distinct means.
        $this->lead(['fit_company'  => 0]);     // outside
        $this->lead(['fit_company'  => 35]);    // a stretch
        $this->lead(['fit_company'  => 70]);    // adjacent
        $this->lead(['fit_budget'   => 30]);    // under discussion
        $this->lead(['fit_budget'   => 60]);    // indicated
        $this->lead(['fit_solution' => 40]);    // needs a partner

        foreach (range(1, 20) as $i) {
            $this->lead();   // never assessed
        }

        $best = $this->lead(['fit_company' => 100]);

        $res = $this->getJson($this->url("/leads/{$best->id}"))->assertOk();

        // Nothing scores higher, so it is top 0% — not top 4% because twenty
        // blank leads were counted below it.
        $this->assertSame(0, $res->json('data.qualification.percentile'));
    }

    public function test_qualifying_a_lead_needs_more_than_being_able_to_read_it(): void
    {
        $this->actor([Permission::PIPELINE_VIEW]);

        $this->postJson($this->url("/leads/{$this->lead()->id}/qualify"), ['fit_company' => 'core'])
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
