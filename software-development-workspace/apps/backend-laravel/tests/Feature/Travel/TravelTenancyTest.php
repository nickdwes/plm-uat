<?php

namespace Tests\Feature\Travel;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Travel\TravelAllowanceRate;
use App\Models\Travel\TravelApprovalStage;
use App\Models\Travel\TravelDestination;
use App\Models\Travel\TravelExpenseCategory;
use App\Models\Travel\TravelRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * One tenant owns a travel request end to end.
 *
 * The organisation on a request decides whose budget pays, whose rate card
 * costs it, whose approvers act on it and who can see it. Every one of those
 * fails quietly if the boundary leaks: a request costed against another
 * tenant's rates looks entirely ordinary on screen, and so does one approved
 * by the wrong company's manager.
 */
class TravelTenancyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $alpha;
    private Organization $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->beta  = Organization::create(['name' => 'Beta',  'slug' => 'beta']);
    }

    private function inOrg(Organization $org): void
    {
        app()->instance('current.organization_id', $org->id);
    }

    private function employee(Organization $org): Employee
    {
        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $org->id,
            'first_name'              => 'Test', 'last_name' => 'Traveller',
            'employment_type'         => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function request(Organization $org): TravelRequest
    {
        return TravelRequest::create([
            'organization_id' => $org->id,
            'employee_id'     => $this->employee($org)->id,
            'departure_date'  => '2026-09-01',
            'return_date'     => '2026-09-03',
            'days'            => 3,
        ]);
    }

    // ── The boundary ──────────────────────────────────────────────────────

    public function test_a_request_cannot_be_moved_between_organisations(): void
    {
        // Moving one would carry its approvals, costing and customer link
        // across a boundary each was validated against separately.
        $this->inOrg($this->alpha);
        $request = $this->request($this->alpha);

        $this->expectException(RuntimeException::class);
        $request->update(['organization_id' => $this->beta->id]);
    }

    public function test_another_organisations_requests_are_not_visible(): void
    {
        $this->inOrg($this->alpha);
        $mine = $this->request($this->alpha);

        $this->inOrg($this->beta);
        $theirs = $this->request($this->beta);

        $this->assertSame([$theirs->id], TravelRequest::pluck('id')->all());

        $this->inOrg($this->alpha);
        $this->assertSame([$mine->id], TravelRequest::pluck('id')->all());
    }

    public function test_reference_numbers_may_repeat_across_organisations(): void
    {
        // Unique per organisation, not globally: two tenants both starting at
        // TR-2026-0001 is normal, and a global unique would surface as a
        // validation error on data the other tenant cannot see.
        $this->inOrg($this->alpha);
        $a = $this->request($this->alpha);
        $a->update(['reference' => 'TR-2026-0001']);

        $this->inOrg($this->beta);
        $b = $this->request($this->beta);
        $b->update(['reference' => 'TR-2026-0001']);

        $this->assertSame('TR-2026-0001', $b->fresh()->reference);
    }

    // ── Rate resolution ───────────────────────────────────────────────────

    private function seedRates(Organization $org, int $categoryId, ?int $destinationId, ?int $gradeId, float $amount): TravelAllowanceRate
    {
        return TravelAllowanceRate::create([
            'organization_id'            => $org->id,
            'travel_expense_category_id' => $categoryId,
            'travel_destination_id'      => $destinationId,
            'job_grade_id'               => $gradeId,
            'amount'                     => $amount,
            'effective_from'             => '2026-01-01',
        ]);
    }

    public function test_the_most_specific_rate_wins(): void
    {
        $this->inOrg($this->alpha);

        $meals = TravelExpenseCategory::create(['organization_id' => $this->alpha->id, 'name' => 'Meals']);
        $nairobi = TravelDestination::create(['organization_id' => $this->alpha->id, 'name' => 'Nairobi']);

        $this->seedRates($this->alpha, $meals->id, null, null, 1000);          // global default
        $expected = $this->seedRates($this->alpha, $meals->id, $nairobi->id, null, 2500);  // destination override

        $winner = TravelAllowanceRate::where('travel_expense_category_id', $meals->id)
            ->where(fn ($q) => $q->whereNull('travel_destination_id')->orWhere('travel_destination_id', $nairobi->id))
            ->effectiveOn('2026-09-01')
            ->mostSpecificFirst()
            ->first();

        $this->assertSame($expected->id, $winner->id, 'A destination-specific rate must beat the global default.');
    }

    public function test_a_rate_that_has_expired_is_not_used(): void
    {
        // A request costed in September must not pick up a rate that ended in
        // June, and one approved in March must keep March's rates.
        $this->inOrg($this->alpha);
        $meals = TravelExpenseCategory::create(['organization_id' => $this->alpha->id, 'name' => 'Meals']);

        $old = $this->seedRates($this->alpha, $meals->id, null, null, 1000);
        $old->update(['effective_to' => '2026-06-30']);

        $this->assertNull(
            TravelAllowanceRate::effectiveOn('2026-09-01')->first(),
            'An expired rate must not be selected.'
        );
        $this->assertNotNull(TravelAllowanceRate::effectiveOn('2026-05-01')->first());
    }

    public function test_another_organisations_rate_card_is_never_reachable(): void
    {
        $betaMeals = TravelExpenseCategory::create(['organization_id' => $this->beta->id, 'name' => 'Meals']);
        $this->seedRates($this->beta, $betaMeals->id, null, null, 9999);

        $this->inOrg($this->alpha);

        $this->assertSame(0, TravelAllowanceRate::count(), 'Alpha must not see Beta rates.');
    }

    // ── Threshold configuration ───────────────────────────────────────────

    public function test_a_stage_without_a_threshold_always_applies(): void
    {
        $stage = new TravelApprovalStage(['stage' => 'department_head', 'sequence' => 1]);
        $this->assertTrue($stage->appliesTo(1.0, 'KES'));
    }

    public function test_a_stage_applies_only_above_its_threshold(): void
    {
        $stage = new TravelApprovalStage([
            'stage' => 'executive', 'sequence' => 4,
            'required_above_amount' => 200000, 'currency' => 'KES',
        ]);

        $this->assertFalse($stage->appliesTo(150000, 'KES'));
        $this->assertFalse($stage->appliesTo(200000, 'KES'), 'The threshold is exclusive.');
        $this->assertTrue($stage->appliesTo(200001, 'KES'));
    }

    public function test_a_threshold_in_another_currency_does_not_apply(): void
    {
        // Comparing 5,000 USD against a 200,000 KES threshold would depend on
        // an exchange rate nobody recorded, and would silently skip the stage.
        $stage = new TravelApprovalStage([
            'stage' => 'executive', 'sequence' => 4,
            'required_above_amount' => 200000, 'currency' => 'KES',
        ]);

        $this->assertFalse($stage->appliesTo(5000, 'USD'));
    }

    public function test_an_inactive_stage_never_applies(): void
    {
        $stage = new TravelApprovalStage(['stage' => 'finance', 'sequence' => 3, 'is_active' => false]);
        $this->assertFalse($stage->appliesTo(1000000, 'KES'));
    }
}
