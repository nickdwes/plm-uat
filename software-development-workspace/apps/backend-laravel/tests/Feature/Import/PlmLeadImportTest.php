<?php

namespace Tests\Feature\Import;

use App\Models\Account\Account;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Pipeline\Lead;
use App\Models\Pipeline\Opportunity;
use App\Models\PlmImportMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Bringing the leads across, and joining them to what they became.
 *
 * The leads matter for the second half of that sentence. Without them the
 * pipeline starts at the opportunity, and nobody can ask the question a
 * pipeline exists to answer — how much of what we chase we win.
 */
class PlmLeadImportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Konvergenz', 'slug' => 'konvergenz']);
        app()->instance('current.organization_id', $this->org->id);

        config()->set('database.connections.plm', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        DB::connection('plm')->getPdo();

        Schema::connection('plm')->create('mdl_projects_leads', function ($t) {
            $t->integer('id')->primary();
            $t->string('name')->nullable();
            $t->integer('account')->nullable();
            $t->text('description')->nullable();
            $t->integer('opportunity')->nullable();
            $t->integer('project')->nullable();
            $t->integer('owner')->nullable();
            $t->integer('author')->nullable();
            $t->string('status')->nullable();
            $t->integer('active')->nullable();
            $t->string('created_at')->nullable();
        });

        Schema::connection('plm')->create('mdl_projects_opportunities', function ($t) {
            $t->integer('id')->primary();
            $t->integer('lead')->nullable();
        });

        $this->account = Account::create([
            'organization_id' => $this->org->id, 'name' => 'Kenya Power', 'status' => 'active',
        ]);

        PlmImportMap::create([
            'organization_id' => $this->org->id, 'source_table' => 'mdl_accounts',
            'source_id' => 9, 'target_type' => Account::class, 'target_id' => $this->account->id,
        ]);
    }

    private function owner(int $legacyId): Employee
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

    private function lead(int $id, array $attributes = []): void
    {
        DB::connection('plm')->table('mdl_projects_leads')->insert(array_merge([
            'id' => $id, 'name' => "Lead {$id}", 'account' => 9, 'status' => 'New',
            'description' => '<p>They want SDWAN across the branches.</p>',
            'active' => 1, 'created_at' => '2024-02-01 09:00:00', 'author' => null,
        ], $attributes));
    }

    /** An opportunity that already exists here, as the earlier import left it. */
    private function opportunity(int $legacyId, ?int $legacyLead = null): Opportunity
    {
        DB::connection('plm')->table('mdl_projects_opportunities')
            ->insert(['id' => $legacyId, 'lead' => $legacyLead]);

        $opportunity = Opportunity::create([
            'organization_id' => $this->org->id, 'account_id' => $this->account->id,
            'name' => "Opportunity {$legacyId}",
        ]);

        PlmImportMap::create([
            'organization_id' => $this->org->id, 'source_table' => 'mdl_projects_opportunities',
            'source_id' => $legacyId, 'target_type' => Opportunity::class,
            'target_id' => $opportunity->id,
        ]);

        return $opportunity;
    }

    private function runImport(array $options = []): string
    {
        $code = Artisan::call('plm:import-leads', array_merge(['--org' => 'konvergenz'], $options));

        $output = Artisan::output();

        $this->assertSame(0, $code, "The import failed:\n{$output}");

        return $output;
    }

    // ── Joining the pipeline back together ────────────────────────────────────

    /** The opportunity names its lead. */
    public function test_an_opportunity_is_joined_to_the_lead_it_grew_from(): void
    {
        $this->lead(5);
        $opportunity = $this->opportunity(80, 5);

        $this->runImport(['--apply' => true]);

        $this->assertSame(Lead::first()->id, $opportunity->fresh()->lead_id);
    }

    /**
     * And where only the lead recorded it, that counts too.
     *
     * Both sides kept the link and neither is complete, so reading both
     * recovers more of the pipeline than trusting either.
     */
    public function test_a_link_recorded_only_on_the_lead_still_joins(): void
    {
        $this->lead(5, ['opportunity' => 80, 'status' => 'Attached to an opportunity']);
        $opportunity = $this->opportunity(80, null);

        $this->runImport(['--apply' => true]);

        $this->assertSame(Lead::first()->id, $opportunity->fresh()->lead_id);
    }

    /** Where the two disagree, the opportunity's own record wins and it is said. */
    public function test_a_disagreement_is_settled_by_the_opportunity_and_reported(): void
    {
        $this->lead(5);
        $this->lead(6, ['opportunity' => 80]);
        $opportunity = $this->opportunity(80, 5);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(Lead::where('name', 'Lead 5')->value('id'), $opportunity->fresh()->lead_id);
        $this->assertStringContainsString('disagree about which belongs to which', $output);
    }

    public function test_a_link_to_something_not_imported_is_counted_not_forced(): void
    {
        $this->lead(5);
        $this->opportunity(80, 999);          // names a lead that does not exist

        $output = $this->runImport(['--apply' => true]);

        $this->assertNull(Opportunity::first()->lead_id);
        $this->assertStringContainsString('1 one side missing', $output);
    }

    // ── The words each system uses ────────────────────────────────────────────

    /** Attached to an opportunity and attached to a project both mean converted. */
    public function test_both_kinds_of_attachment_mean_converted(): void
    {
        $this->lead(1, ['status' => 'Attached to an opportunity']);
        $this->lead(2, ['status' => 'Attached to a Project']);
        $this->lead(3, ['status' => 'Lost']);

        $this->runImport(['--apply' => true]);

        $this->assertSame('converted', Lead::where('name', 'Lead 1')->value('status'));
        $this->assertSame('converted', Lead::where('name', 'Lead 2')->value('status'));
        $this->assertSame('unqualified', Lead::where('name', 'Lead 3')->value('status'));
    }

    /**
     * "Cold" has no equivalent, and saying so is the point.
     *
     * It meant a lead that went quiet rather than one judged a poor fit.
     * Unqualified is the closest available and not the same thing.
     */
    public function test_a_cold_lead_is_recorded_as_unqualified_and_the_loss_is_stated(): void
    {
        $this->lead(1, ['status' => 'Cold']);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame('unqualified', Lead::first()->status);
        $this->assertStringContainsString('no word for a lead that simply went quiet', $output);
    }

    public function test_an_unknown_status_lands_somewhere_safe_and_is_named(): void
    {
        $this->lead(1, ['status' => 'Marinating']);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame('new', Lead::first()->status);
        $this->assertStringContainsString('Marinating', $output);
    }

    // ── One field into two ────────────────────────────────────────────────────

    /**
     * The old system had one description; this has a card line and a body.
     *
     * `summary` holds 255 characters and is shown on the board card. The
     * descriptions run to two and a half thousand.
     */
    public function test_a_long_description_goes_where_there_is_room_for_it(): void
    {
        $long = 'The customer needs a full refresh. ' . str_repeat('Detail follows. ', 60);

        $this->lead(1, ['description' => "<p>{$long}</p>"]);

        $this->runImport(['--apply' => true]);

        $lead = Lead::firstOrFail();

        $this->assertGreaterThan(500, mb_strlen((string) $lead->problem_statement));
        $this->assertLessThanOrEqual(255, mb_strlen((string) $lead->summary));
        $this->assertStringStartsWith('The customer needs a full refresh.', $lead->summary);
    }

    /** A card that stops mid-word reads as broken rather than as abbreviated. */
    public function test_the_card_line_stops_at_a_sentence_or_a_word(): void
    {
        $this->lead(1, ['description' => '<p>' . str_repeat('word ', 200) . '</p>']);

        $this->runImport(['--apply' => true]);

        $summary = (string) Lead::first()->summary;

        $this->assertTrue(str_ends_with($summary, '…'));
        $this->assertStringNotContainsString('wor…', $summary);
    }

    public function test_a_short_description_is_kept_whole_in_both(): void
    {
        $this->lead(1);

        $this->runImport(['--apply' => true]);

        $lead = Lead::firstOrFail();

        $this->assertSame('They want SDWAN across the branches.', $lead->summary);
        $this->assertSame('They want SDWAN across the branches.', $lead->problem_statement);
    }

    // ── What it will not invent ───────────────────────────────────────────────

    public function test_a_lead_with_no_customer_is_held_back_and_named(): void
    {
        $this->lead(27, ['account' => null]);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(0, Lead::count());
        $this->assertStringContainsString('Lead 27 has no customer', $output);
    }

    /** An owner at another company leaves the lead unowned rather than misowned. */
    public function test_an_owner_who_was_not_imported_leaves_the_lead_unowned(): void
    {
        $mine = $this->owner(42);

        $this->lead(1, ['owner' => 42]);
        $this->lead(2, ['owner' => 777]);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame($mine->id, Lead::where('name', 'Lead 1')->value('owner_id'));
        $this->assertNull(Lead::where('name', 'Lead 2')->value('owner_id'));
        $this->assertStringContainsString('1 nobody to own them', $output);
    }

    /**
     * Priority is required here and the old system had no such field.
     *
     * Everything arrives in the middle, and the summary says so — a board
     * where every lead is medium priority is a board where the column means
     * nothing.
     */
    public function test_priority_is_uniform_because_the_old_system_had_none(): void
    {
        $this->lead(1);
        $this->lead(2);

        $this->runImport(['--apply' => true]);

        $this->assertSame(['medium', 'medium'], Lead::orderBy('name')->pluck('priority')->all());
    }

    /**
     * `created_by` wants a user and the old system only ever named employees.
     *
     * Joined through the employee's own login rather than by hoping the two id
     * spaces line up — they do not, and a wrong one here credits the record to
     * a different person entirely.
     */
    public function test_the_creator_is_resolved_through_the_employees_login(): void
    {
        $employee = $this->owner(42);
        $user = \App\Models\User::create([
            'name' => 'Ann', 'email' => 'ann42@kns.co.ke', 'password' => bcrypt('x'),
        ]);
        $employee->forceFill(['user_id' => $user->id])->save();

        $this->lead(1, ['author' => 42]);

        $this->runImport(['--apply' => true]);

        $this->assertSame($user->id, Lead::first()->created_by);
    }

    /** Failing an owner, whoever raised it — an owned lead can be asked about. */
    public function test_the_author_owns_a_lead_that_names_no_owner(): void
    {
        $raiser = $this->owner(42);

        $this->lead(1, ['owner' => null, 'author' => 42]);

        $this->runImport(['--apply' => true]);

        $this->assertSame($raiser->id, Lead::first()->owner_id);
    }

    // ── The usual promises ────────────────────────────────────────────────────

    public function test_nothing_is_written_without_apply(): void
    {
        $this->lead(1);

        $output = $this->runImport();

        $this->assertSame(0, Lead::count());
        $this->assertStringContainsString('Nothing will be written', $output);
    }

    public function test_running_it_twice_updates_rather_than_duplicates(): void
    {
        $this->lead(1);

        $this->runImport(['--apply' => true]);

        DB::connection('plm')->table('mdl_projects_leads')->where('id', 1)
            ->update(['status' => 'Lost']);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(1, Lead::count());
        $this->assertSame('unqualified', Lead::first()->status);
        $this->assertStringContainsString('0 created, 1 updated', $output);
    }
}
