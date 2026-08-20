<?php

namespace Tests\Feature\Import;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\PlmImportMap;
use App\Models\Travel\TravelApproval;
use App\Models\Travel\TravelDestination;
use App\Models\Travel\TravelRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Bringing the travel requests across from the old PLM.
 *
 * These are imported to be studied, not to be worked — so what matters is that
 * the decisions arrive as they were made: the approver who made them, the date
 * they made them, and the stage that never decided anything at all.
 */
class PlmTravelImportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Konvergenz', 'slug' => 'konvergenz']);
        app()->instance('current.organization_id', $this->org->id);

        config()->set('database.connections.plm', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        DB::connection('plm')->getPdo();

        $this->buildLegacySchema();
    }

    private function buildLegacySchema(): void
    {
        $schema = Schema::connection('plm');

        $schema->create('mdl_travel_r', function ($t) {
            $t->integer('id')->primary();
            $t->integer('destination')->nullable();
            $t->integer('employee')->nullable();
            $t->string('from')->nullable();
            $t->string('to')->nullable();
            $t->integer('travel_mode')->nullable();
            $t->integer('client')->nullable();
            $t->string('travel_cost')->nullable();
            $t->text('misc')->nullable();
            $t->text('travel_reason')->nullable();
            $t->string('status')->nullable();
            $t->string('created_at')->nullable();

            foreach (['hod', 'cto', 'finance', 'cfo'] as $stage) {
                $t->string("{$stage}_status")->nullable();
                $t->text("{$stage}_comment")->nullable();
                $t->integer($stage)->nullable();
                $t->string("{$stage}_approval_date")->nullable();
            }
        });

        $schema->create('mdl_travel_r_destionations', function ($t) {
            $t->integer('id')->primary();
            $t->string('name')->nullable();
            $t->integer('tier')->nullable();
        });

        $schema->create('mdl_travel_r_d_tiers', function ($t) {
            $t->integer('id')->primary();
            $t->string('name')->nullable();
        });

        $schema->create('mdl_travel_r_travel_mode', function ($t) {
            $t->integer('id')->primary();
            $t->string('name')->nullable();
        });

        $plm = DB::connection('plm');
        $plm->table('mdl_travel_r_d_tiers')->insert([
            ['id' => 1, 'name' => 'City T1'], ['id' => 5, 'name' => 'International T1'],
        ]);
        $plm->table('mdl_travel_r_destionations')->insert([
            ['id' => 1, 'name' => 'Mombasa', 'tier' => 1],
            ['id' => 2, 'name' => 'Dubai', 'tier' => 5],
        ]);
        $plm->table('mdl_travel_r_travel_mode')->insert([
            ['id' => 1, 'name' => 'Air'], ['id' => 2, 'name' => 'Private Car'],
            ['id' => 9, 'name' => 'Hovercraft'],
        ]);
    }

    private function traveller(int $legacyId): Employee
    {
        $employee = Employee::create([
            'employee_number' => "EMP-{$legacyId}", 'primary_organization_id' => $this->org->id,
            'first_name' => 'Ann', 'last_name' => "Number{$legacyId}",
            'work_email' => "ann{$legacyId}@kns.co.ke", 'employment_type' => 'full_time',
            'status' => 'active', 'date_hired' => '2020-01-01',
        ]);

        PlmImportMap::create([
            'organization_id' => $this->org->id, 'source_table' => 'mdl_hr_employees',
            'source_id' => $legacyId, 'target_type' => Employee::class, 'target_id' => $employee->id,
        ]);

        return $employee;
    }

    private function request(int $id, array $attributes = []): void
    {
        DB::connection('plm')->table('mdl_travel_r')->insert(array_merge([
            'id' => $id, 'employee' => 42, 'destination' => 1, 'travel_mode' => 1,
            'from' => '2024-05-06', 'to' => '2024-05-08', 'travel_cost' => '45000',
            'travel_reason' => 'Site survey.', 'status' => 'Processing',
            'hod_status' => 'Approved', 'hod' => 7, 'hod_approval_date' => '2024-05-01 10:00:00',
            'cto_status' => 'Pending', 'finance_status' => 'Pending', 'cfo_status' => 'Pending',
            'created_at' => '2024-04-30 09:00:00',
        ], $attributes));
    }

    private function runImport(array $options = []): string
    {
        $code = Artisan::call('plm:import-travel', array_merge(['--org' => 'konvergenz'], $options));

        $output = Artisan::output();

        $this->assertSame(0, $code, "The import failed:\n{$output}");

        return $output;
    }

    // ── Decisions are recorded, not replayed ──────────────────────────────────

    /**
     * The approver and the date they decided, exactly as they were.
     *
     * Pushing these through the live workflow would stamp today on a decision
     * made eighteen months ago, and that timestamp is the whole reason for
     * importing them.
     */
    public function test_a_decision_keeps_the_date_it_was_made(): void
    {
        $traveller = $this->traveller(42);
        $head      = $this->traveller(7);

        $this->request(1, ['hod_comment' => 'Approved, keep it to two nights.']);

        $this->runImport(['--apply' => true]);

        $approval = TravelApproval::where('stage', 'department_head')->firstOrFail();

        $this->assertSame('approved', $approval->status);
        $this->assertSame('2024-05-01', $approval->decided_at->toDateString());
        $this->assertSame($head->id, $approval->decided_by_employee_id);
        $this->assertSame('Approved, keep it to two nights.', $approval->comment);
        $this->assertSame($traveller->id, TravelRequest::first()->employee_id);
    }

    /** A stage nobody has reached has no decision date to record. */
    public function test_a_pending_stage_has_no_decision_date(): void
    {
        $this->traveller(42);

        $this->request(1, ['cto_approval_date' => '2024-05-02 10:00:00']);

        $this->runImport(['--apply' => true]);

        $approval = TravelApproval::where('stage', 'function_head')->firstOrFail();

        $this->assertSame('pending', $approval->status);
        $this->assertNull($approval->decided_at, 'a pending stage has decided nothing');
    }

    /**
     * The stage that was skipped in practice is imported as skipped.
     *
     * All 102 requests sit pending at the second stage while Finance approved
     * eighteen. Smoothing that into "approved", or dropping the row, would
     * erase the finding these records are being imported for.
     */
    public function test_a_stage_that_was_bypassed_is_reported_rather_than_tidied(): void
    {
        $this->traveller(42);

        $this->request(1, ['finance_status' => 'Approved', 'finance_approval_date' => '2024-05-03 09:00:00']);
        $this->request(2, ['finance_status' => 'Approved', 'finance_approval_date' => '2024-05-03 09:00:00']);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(2, TravelApproval::where('stage', 'function_head')
            ->where('status', 'pending')->count());
        $this->assertStringContainsString('never decided anything', $output);
        $this->assertStringContainsString('function_head', $output);
    }

    /** All four stages exist on every request, in their proper order. */
    public function test_every_request_carries_the_whole_chain(): void
    {
        $this->traveller(42);

        $this->request(1);

        $this->runImport(['--apply' => true]);

        $this->assertSame(
            ['department_head', 'function_head', 'finance', 'executive'],
            TravelApproval::orderBy('sequence')->pluck('stage')->all(),
        );
    }

    // ── The request's own state ───────────────────────────────────────────────

    /**
     * Read from the decisions, not from the old status column.
     *
     * That column says "Processing" for seventy-one requests, including ones
     * the CFO had signed off.
     */
    public function test_status_is_derived_from_the_decisions_not_the_old_column(): void
    {
        $this->traveller(42);

        $this->request(1, ['status' => 'Processing', 'hod_status' => 'Approved',
            'cto_status' => 'Approved', 'finance_status' => 'Approved', 'cfo_status' => 'Approved']);
        $this->request(2, ['status' => 'Processing', 'hod_status' => 'Declined']);
        $this->request(3, ['status' => 'Approved']);

        $this->runImport(['--apply' => true]);

        $this->assertSame('approved', TravelRequest::where('reference', 'TR-PLM-00001')->value('status'));
        $this->assertSame('rejected', TravelRequest::where('reference', 'TR-PLM-00002')->value('status'));
        // Signed off by nobody but the head — still somewhere in the chain.
        $this->assertSame('in_approval', TravelRequest::where('reference', 'TR-PLM-00003')->value('status'));
    }

    // ── Destinations ──────────────────────────────────────────────────────────

    /**
     * The company's real destination list, and where each one is.
     *
     * Twenty-five places against the five seeded here as examples. Whether
     * somewhere is international comes from its tier, the only place the old
     * system recorded it.
     */
    public function test_missing_destinations_are_created_with_their_reach(): void
    {
        $this->traveller(42);

        $this->request(1, ['destination' => 2]);       // Dubai, International T1

        $this->runImport(['--apply' => true]);

        $dubai = TravelDestination::where('name', 'Dubai')->firstOrFail();

        $this->assertTrue($dubai->is_international);
        $this->assertNull($dubai->country);
        $this->assertTrue(TravelDestination::where('name', 'Mombasa')->value('is_international') === false);
        $this->assertSame('international', TravelRequest::first()->travel_type);
    }

    public function test_a_destination_we_already_have_is_matched_not_duplicated(): void
    {
        TravelDestination::create([
            'organization_id' => $this->org->id, 'name' => 'Mombasa',
            'country' => 'KE', 'is_international' => false, 'is_active' => true,
        ]);

        $this->traveller(42);
        $this->request(1);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(1, TravelDestination::where('name', 'Mombasa')->count());
        $this->assertStringContainsString('1 already here', $output);
    }

    // ── What it will not invent ───────────────────────────────────────────────

    /** A travel request with no traveller is not a record of anything. */
    public function test_a_request_whose_traveller_was_not_imported_is_held_back(): void
    {
        $this->request(1, ['employee' => 999]);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(0, TravelRequest::count());
        $this->assertStringContainsString('traveller was not imported', $output);
    }

    public function test_a_request_with_no_dates_is_held_back_and_named(): void
    {
        $this->traveller(42);

        $this->request(1, ['from' => null, 'to' => null]);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(0, TravelRequest::count());
        $this->assertStringContainsString('no travel dates', $output);
    }

    /**
     * A third of them carry no figure at all.
     *
     * The column demands a number, so it is zero — but the count is reported,
     * because "a third of requests had no cost" is itself a fact about how the
     * process ran.
     */
    public function test_requests_with_no_cost_are_counted_out_loud(): void
    {
        $this->traveller(42);

        $this->request(1, ['travel_cost' => null]);
        $this->request(2, ['travel_cost' => '45000']);

        $output = $this->runImport(['--apply' => true]);

        $this->assertStringContainsString('1 no cost recorded', $output);
        $this->assertEquals(45000, TravelRequest::where('reference', 'TR-PLM-00002')->value('total_claimed'));
    }

    public function test_a_mode_we_do_not_offer_is_left_unset_and_named(): void
    {
        $this->traveller(42);

        $this->request(1, ['travel_mode' => 9]);       // Hovercraft

        $output = $this->runImport(['--apply' => true]);

        $this->assertNull(TravelRequest::first()->travel_mode);
        $this->assertStringContainsString('hovercraft', $output);
    }

    public function test_the_modes_we_do_offer_are_translated(): void
    {
        $this->traveller(42);

        $this->request(1, ['travel_mode' => 1]);       // Air
        $this->request(2, ['travel_mode' => 2]);       // Private Car

        $this->runImport(['--apply' => true]);

        $this->assertSame('air', TravelRequest::where('reference', 'TR-PLM-00001')->value('travel_mode'));
        $this->assertSame('road', TravelRequest::where('reference', 'TR-PLM-00002')->value('travel_mode'));
    }

    /** A trip out and back on one day is a day, not nought. */
    public function test_the_length_of_a_trip_counts_both_ends(): void
    {
        $this->traveller(42);

        $this->request(1, ['from' => '2024-05-06', 'to' => '2024-05-08']);
        $this->request(2, ['from' => '2024-05-06', 'to' => '2024-05-06']);

        $this->runImport(['--apply' => true]);

        $this->assertSame(3, TravelRequest::where('reference', 'TR-PLM-00001')->value('days'));
        $this->assertSame(1, TravelRequest::where('reference', 'TR-PLM-00002')->value('days'));
    }

    // ── The usual promises ────────────────────────────────────────────────────

    public function test_nothing_is_written_without_apply(): void
    {
        $this->traveller(42);
        $this->request(1);

        $output = $this->runImport();

        $this->assertSame(0, TravelRequest::count());
        $this->assertSame(0, TravelApproval::count());
        $this->assertStringContainsString('Nothing will be written', $output);
    }

    public function test_running_it_twice_updates_rather_than_duplicates(): void
    {
        $this->traveller(42);
        $this->request(1);

        $this->runImport(['--apply' => true]);

        DB::connection('plm')->table('mdl_travel_r')->where('id', 1)
            ->update(['finance_status' => 'Approved', 'finance_approval_date' => '2024-05-04 09:00:00']);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(1, TravelRequest::count());
        $this->assertSame(4, TravelApproval::count());
        $this->assertSame('approved', TravelApproval::where('stage', 'finance')->value('status'));
        $this->assertStringContainsString('0 created, 1 updated', $output);
    }
}
