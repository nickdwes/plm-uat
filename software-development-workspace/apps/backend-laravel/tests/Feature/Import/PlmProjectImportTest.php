<?php

namespace Tests\Feature\Import;

use App\Models\Account\Account;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Pipeline\Opportunity;
use App\Models\Pipeline\OpportunityPhase;
use App\Models\PlmImportMap;
use App\Models\Project\Project;
use App\Models\Project\ProjectComment;
use App\Models\Support\Ticket;
use App\Models\Task\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Bringing the delivery side across from the old PLM.
 *
 * The judgements are the ones the data forces: an effort estimate that is
 * really minutes, a project state we have no word for, people who work for
 * another company, and a schema invariant that historic data cannot satisfy.
 */
class PlmProjectImportTest extends TestCase
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

        $this->buildLegacySchema();
        $this->seedTargets();
    }

    private function buildLegacySchema(): void
    {
        $schema = Schema::connection('plm');

        $schema->create('mdl_projects_opportunities', function ($t) {
            $t->integer('id')->primary();
            $t->string('name')->nullable();
            $t->integer('account')->nullable();
            $t->text('description')->nullable();
            $t->text('gap_analysis_comment')->nullable();
            $t->string('budget')->nullable();
            $t->integer('phase')->nullable();
            $t->integer('practice')->nullable();
            $t->integer('opportunity_type')->nullable();
            $t->integer('presale_lead')->nullable();
            $t->integer('author')->nullable();
            $t->string('estimated_close_date')->nullable();
            $t->string('account_weight')->nullable();
            $t->string('know_procurement')->nullable();
            $t->string('deal_registered')->nullable();
            $t->string('compliance_pressure')->nullable();
            $t->string('oem_pressure')->nullable();
            $t->string('prior_deal_clossed')->nullable();
            $t->string('commited')->nullable();
            $t->string('year_commited')->nullable();
            $t->string('commited_quater')->nullable();
            $t->string('discuss_in_weekly_meetings')->nullable();
            $t->string('created_at')->nullable();
        });

        $schema->create('mdl_projects_history', function ($t) {
            $t->integer('id')->primary();
            $t->string('description')->nullable();
            $t->integer('opportunity')->nullable();
            $t->string('created_at')->nullable();
        });

        $schema->create('mdl_projects', function ($t) {
            $t->integer('id')->primary();
            $t->string('name')->nullable();
            $t->string('status')->nullable();
            $t->integer('account')->nullable();
            $t->text('description')->nullable();
            $t->integer('opportunity')->nullable();
            $t->integer('project_manager')->nullable();
            $t->string('start_date')->nullable();
            $t->string('end_date')->nullable();
            $t->string('created_at')->nullable();
        });

        $schema->create('mdl_tasks', function ($t) {
            $t->integer('id')->primary();
            $t->string('name')->nullable();
            $t->text('details')->nullable();
            $t->integer('assigned_to')->nullable();
            $t->integer('project')->nullable();
            $t->integer('opportunity')->nullable();
            $t->integer('account')->nullable();
            $t->integer('ticket')->nullable();
            $t->string('status')->nullable();
            $t->string('priority')->nullable();
            $t->string('due_date')->nullable();
            $t->string('start_date')->nullable();
            $t->string('starting_date')->nullable();
            $t->string('end_date')->nullable();
            $t->string('work_effort_estimate')->nullable();
            $t->string('created_at')->nullable();
        });

        $schema->create('mdl_tasks_comments', function ($t) {
            $t->integer('id')->primary();
            $t->integer('task')->nullable();
            $t->text('comment')->nullable();
            $t->integer('author')->nullable();
            $t->string('created_at')->nullable();
        });

        $schema->create('mdl_projects_opportunity_phases', function ($t) {
            $t->integer('id')->primary();
            $t->string('name')->nullable();
        });

        DB::connection('plm')->table('mdl_projects_opportunity_phases')->insert([
            ['id' => 11, 'name' => 'Win'],
            ['id' => 99, 'name' => 'A Phase We Never Had'],
        ]);
    }

    private function seedTargets(): void
    {
        $this->account = Account::create([
            'organization_id' => $this->org->id, 'name' => 'Kenya Power', 'status' => 'active',
        ]);

        PlmImportMap::create([
            'organization_id' => $this->org->id, 'source_table' => 'mdl_accounts',
            'source_id' => 9, 'target_type' => Account::class, 'target_id' => $this->account->id,
        ]);

        OpportunityPhase::create([
            'organization_id' => $this->org->id, 'name' => 'Win', 'position' => 11,
        ]);
    }

    /** An employee who came across, and one who works for another company. */
    private function ours(int $legacyId): Employee
    {
        $employee = Employee::create([
            'employee_number' => "EMP-{$legacyId}", 'primary_organization_id' => $this->org->id,
            'first_name' => 'Ann', 'last_name' => "Number{$legacyId}",
            'work_email' => "ann{$legacyId}@kns.co.ke",
            'employment_type' => 'full_time', 'status' => 'active', 'date_hired' => '2020-01-01',
        ]);

        PlmImportMap::create([
            'organization_id' => $this->org->id, 'source_table' => 'mdl_hr_employees',
            'source_id' => $legacyId, 'target_type' => Employee::class, 'target_id' => $employee->id,
        ]);

        return $employee;
    }

    private function opportunity(int $id, array $attributes = []): void
    {
        DB::connection('plm')->table('mdl_projects_opportunities')->insert(array_merge([
            'id' => $id, 'name' => "Opportunity {$id}", 'account' => 9, 'phase' => 11,
            'budget' => '2500000', 'estimated_close_date' => '2025-06-30',
            'deal_registered' => 'yes', 'oem_pressure' => '0', 'author' => null,
            'created_at' => '2024-01-10 09:00:00',
        ], $attributes));
    }

    private function project(int $id, array $attributes = []): void
    {
        DB::connection('plm')->table('mdl_projects')->insert(array_merge([
            'id' => $id, 'name' => "Project {$id}", 'account' => 9, 'status' => 'Ongoing',
            'created_at' => '2024-02-01 09:00:00',
        ], $attributes));
    }

    private function task(int $id, array $attributes = []): void
    {
        DB::connection('plm')->table('mdl_tasks')->insert(array_merge([
            'id' => $id, 'name' => "Task {$id}", 'status' => 'Completed', 'priority' => 'High',
            'due_date' => '2024-03-01', 'end_date' => '2024-02-28 16:00:00',
            'created_at' => '2024-02-05 09:00:00',
        ], $attributes));
    }

    private function runImport(array $options = []): string
    {
        $code = Artisan::call('plm:import-projects', array_merge(['--org' => 'konvergenz'], $options));

        $output = Artisan::output();

        $this->assertSame(0, $code, "The import failed:\n{$output}");

        return $output;
    }

    // ── The chain ─────────────────────────────────────────────────────────────

    /**
     * Each step resolves against the one before it.
     *
     * A task finds its project, which finds its opportunity, which finds its
     * customer — which is why this is one command and not four.
     */
    public function test_a_task_reaches_its_customer_through_the_whole_chain(): void
    {
        $this->opportunity(1);
        $this->project(5, ['opportunity' => 1]);
        $this->task(9, ['project' => 5]);

        $this->runImport(['--apply' => true]);

        $task = Task::firstOrFail();
        $project = Project::firstOrFail();

        $this->assertSame($project->id, $task->project_id);
        $this->assertSame(Opportunity::first()->id, $project->opportunity_id);
        $this->assertSame($this->account->id, $project->account_id);
    }

    /** Three quarters of them belong to no project, and the schema expects that. */
    public function test_a_task_with_no_project_is_kept_and_counted(): void
    {
        $this->task(1);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(1, Task::count());
        $this->assertNull(Task::first()->project_id);
        $this->assertStringContainsString('1 no project', $output);
    }

    // ── Units and vocabularies ────────────────────────────────────────────────

    /**
     * The effort column is minutes, whatever it is called.
     *
     * 480 is a working day and 2400 a week. Copying the number straight across
     * would have put "262800 days" on a task that took a fortnight, and the
     * figure would have looked deliberate.
     */
    public function test_effort_is_converted_from_minutes_to_days(): void
    {
        $this->task(1, ['work_effort_estimate' => '480']);
        $this->task(2, ['work_effort_estimate' => '2400']);

        $this->runImport(['--apply' => true]);

        $this->assertEquals(1, Task::where('title', 'Task 1')->value('effort_estimate_days'));
        $this->assertEquals(5, Task::where('title', 'Task 2')->value('effort_estimate_days'));
    }

    /** Rubbish in that column is dropped and named, not rounded into shape. */
    public function test_an_impossible_estimate_is_refused_rather_than_trimmed(): void
    {
        $this->task(1, ['work_effort_estimate' => '9223372036854775807']);
        $this->task(2, ['work_effort_estimate' => '-6720']);

        $output = $this->runImport(['--apply' => true]);

        $this->assertNull(Task::where('title', 'Task 1')->value('effort_estimate_days'));
        $this->assertNull(Task::where('title', 'Task 2')->value('effort_estimate_days'));
        $this->assertStringContainsString('beyond anything this column can hold', $output);
    }

    /**
     * A project in "Support" has been delivered.
     *
     * We have no word for it — seventeen projects were handed over and are
     * being supported — so they are called delivered and the count is stated.
     */
    public function test_a_project_in_support_is_recorded_as_delivered_and_counted(): void
    {
        $this->project(1, ['status' => 'Support']);
        $this->project(2, ['status' => 'On Hold']);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame('delivered', Project::where('name', 'Project 1')->value('status'));
        $this->assertSame('on_hold', Project::where('name', 'Project 2')->value('status'));
        $this->assertStringContainsString('1 in support', $output);
    }

    public function test_an_unknown_status_lands_somewhere_safe_and_is_reported(): void
    {
        $this->project(1, ['status' => 'Whatever This Is']);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame('kickoff', Project::first()->status);
        $this->assertStringContainsString('Whatever This Is', $output);
    }

    // ── People ────────────────────────────────────────────────────────────────

    /**
     * Somebody at another company cannot own a task here.
     *
     * Only Konvergenz staff came across. An owner is read as fact by every
     * board and every workload figure, so it is left unset rather than pointed
     * at a plausible colleague.
     */
    public function test_a_task_owned_by_somebody_not_imported_is_left_unassigned(): void
    {
        $ours = $this->ours(42);

        $this->task(1, ['assigned_to' => 42]);
        $this->task(2, ['assigned_to' => 777]);          // works for Tiberbu

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame($ours->id, Task::where('title', 'Task 1')->value('assigned_to'));
        $this->assertNull(Task::where('title', 'Task 2')->value('assigned_to'));
        $this->assertStringContainsString('1 nobody to assign', $output);
    }

    // ── The schema's own limits ───────────────────────────────────────────────

    /**
     * Only one task may name a ticket as its source.
     *
     * The constraint stops the ticket-to-task generator producing duplicates
     * and is worth more than the extra links. The earliest keeps it; the rest
     * are counted, so the limit is stated rather than discovered.
     */
    public function test_only_the_first_task_from_a_ticket_carries_the_link(): void
    {
        app(\App\Services\Support\SupportDefaults::class)->ensureFor($this->org->id);

        $ticket = Ticket::create([
            'organization_id' => $this->org->id, 'reference' => 'SD-2024-00001',
            'subject' => 'Link down', 'body' => 'No connectivity.',
            'opened_at' => '2024-01-01 09:00:00',
            'ticket_status_id' => \App\Models\Support\TicketStatus::where('organization_id', $this->org->id)
                ->where('is_default', true)->value('id'),
        ]);

        PlmImportMap::create([
            'organization_id' => $this->org->id, 'source_table' => 'mdl_tickets',
            'source_id' => 500, 'target_type' => Ticket::class, 'target_id' => $ticket->id,
        ]);

        $this->task(1, ['ticket' => 500]);
        $this->task(2, ['ticket' => 500]);

        $output = $this->runImport(['--apply' => true]);

        $this->assertEquals($ticket->id, Task::where('title', 'Task 1')->value('generated_from_id'));
        $this->assertNull(Task::where('title', 'Task 2')->value('generated_from_id'));
        $this->assertStringContainsString('share a source ticket', $output);
    }

    // ── Opportunities ─────────────────────────────────────────────────────────

    public function test_an_opportunitys_qualification_answers_come_across(): void
    {
        $this->opportunity(1, ['deal_registered' => 'yes', 'oem_pressure' => '0',
            'compliance_pressure' => null]);

        $this->runImport(['--apply' => true]);

        $opportunity = Opportunity::firstOrFail();

        $this->assertTrue($opportunity->deal_registered);
        $this->assertFalse($opportunity->oem_pressure);
        // Never answered is not the same as answered no.
        $this->assertNull($opportunity->compliance_pressure);
        $this->assertEquals(2500000, $opportunity->amount);
        $this->assertSame(OpportunityPhase::first()->id, $opportunity->opportunity_phase_id);
    }

    public function test_a_phase_we_do_not_have_is_left_unset_and_named(): void
    {
        $this->opportunity(1, ['phase' => 99]);

        $output = $this->runImport(['--apply' => true]);

        $this->assertNull(Opportunity::first()->opportunity_phase_id);
        $this->assertStringContainsString('A Phase We Never Had', $output);
    }

    // ── Who owns it, and when things happened ─────────────────────────────────

    /**
     * Whoever raised it owns it.
     *
     * The old system had no owner field. `author` is an employee id — across
     * the leads it matches the owner's employee id 149 times against 3 for
     * their user id — and it agrees with the originating lead's owner in 113
     * of 129 cases. Without this every opportunity arrives ownerless.
     */
    public function test_an_opportunity_is_owned_by_whoever_raised_it(): void
    {
        $raiser = $this->ours(42);

        $this->opportunity(1, ['author' => 42]);

        $this->runImport(['--apply' => true]);

        $this->assertSame($raiser->id, Opportunity::first()->owner_id);
    }

    /** `created_by` wants a user, and the old system only named employees. */
    public function test_the_creator_is_resolved_through_the_employees_login(): void
    {
        $employee = $this->ours(42);
        $user = \App\Models\User::create([
            'name' => 'Ann', 'email' => 'ann42@kns.co.ke', 'password' => bcrypt('x'),
        ]);
        $employee->forceFill(['user_id' => $user->id])->save();

        $this->opportunity(1, ['author' => 42]);

        $this->runImport(['--apply' => true]);

        $this->assertSame($user->id, Opportunity::first()->created_by);
    }

    /**
     * Qualification has a date only in the activity trail.
     *
     * The opportunity's own row records that it was qualified, never when —
     * and "when" is the half that says how long qualifying takes.
     */
    public function test_qualification_and_budget_dates_come_from_the_trail(): void
    {
        $this->opportunity(1);

        DB::connection('plm')->table('mdl_projects_history')->insert([
            ['id' => 1, 'opportunity' => 1, 'description' => 'Opportunity marked as qualified.',
             'created_at' => '2024-03-08 11:00:00'],
            ['id' => 2, 'opportunity' => 1, 'description' => 'Budget confirmed by customer.',
             'created_at' => '2024-04-02 15:30:00'],
        ]);

        $this->runImport(['--apply' => true]);

        $deal = Opportunity::firstOrFail();

        $this->assertSame('2024-03-08', $deal->qualified_at->toDateString());
        $this->assertSame('2024-04-02', $deal->budget_confirmed_at->toDateString());
    }

    /** Qualifying happens once; a later identical row is somebody re-saving. */
    public function test_the_earliest_of_a_repeated_event_is_the_one_kept(): void
    {
        $this->opportunity(1);

        DB::connection('plm')->table('mdl_projects_history')->insert([
            ['id' => 1, 'opportunity' => 1, 'description' => 'Opportunity marked as qualified.',
             'created_at' => '2024-06-01 09:00:00'],
            ['id' => 2, 'opportunity' => 1, 'description' => 'Opportunity marked as qualified.',
             'created_at' => '2024-03-08 11:00:00'],
        ]);

        $this->runImport(['--apply' => true]);

        $this->assertSame('2024-03-08', Opportunity::first()->qualified_at->toDateString());
    }

    public function test_an_opportunity_with_no_trail_has_no_invented_dates(): void
    {
        $this->opportunity(1);

        $this->runImport(['--apply' => true]);

        $deal = Opportunity::firstOrFail();

        $this->assertNull($deal->qualified_at);
        $this->assertNull($deal->budget_confirmed_at);
    }

    // ── Comments ──────────────────────────────────────────────────────────────

    public function test_what_people_wrote_is_kept_against_the_task(): void
    {
        $author = $this->ours(42);

        $this->task(1);

        DB::connection('plm')->table('mdl_tasks_comments')->insert([
            ['id' => 1, 'task' => 1, 'comment' => '<p>Task done</p>', 'author' => 42,
             'created_at' => '2024-02-28 16:10:00'],
            ['id' => 2, 'task' => 4242, 'comment' => '<p>Orphan</p>', 'author' => 42,
             'created_at' => '2024-02-28 16:10:00'],
        ]);

        $output = $this->runImport(['--apply' => true]);

        $comment = ProjectComment::firstOrFail();

        $this->assertSame('<p>Task done</p>', $comment->body);
        $this->assertSame(Task::first()->id, $comment->task_id);
        $this->assertSame($author->id, $comment->employee_id);
        $this->assertStringContainsString('1 orphaned', $output);
    }

    public function test_running_it_twice_does_not_repeat_the_discussion(): void
    {
        $this->task(1);

        DB::connection('plm')->table('mdl_tasks_comments')->insert([
            ['id' => 1, 'task' => 1, 'comment' => 'Once.', 'created_at' => '2024-02-28 16:10:00'],
        ]);

        $this->runImport(['--apply' => true]);
        $this->runImport(['--apply' => true]);

        $this->assertSame(1, Task::count());
        $this->assertSame(1, ProjectComment::count());
    }

    // ── The usual promises ────────────────────────────────────────────────────

    public function test_nothing_is_written_without_apply(): void
    {
        $this->opportunity(1);
        $this->project(1);
        $this->task(1);

        $output = $this->runImport();

        $this->assertSame(0, Opportunity::count());
        $this->assertSame(0, Project::count());
        $this->assertSame(0, Task::count());
        $this->assertStringContainsString('Nothing will be written', $output);
    }

    public function test_running_it_twice_updates_rather_than_duplicates(): void
    {
        $this->opportunity(1);
        $this->project(1);
        $this->task(1);

        $this->runImport(['--apply' => true]);

        DB::connection('plm')->table('mdl_tasks')->where('id', 1)->update(['name' => 'Renamed']);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(1, Task::count());
        $this->assertSame('Renamed', Task::first()->title);
        $this->assertStringContainsString('0 created, 1 updated', $output);
    }
}
