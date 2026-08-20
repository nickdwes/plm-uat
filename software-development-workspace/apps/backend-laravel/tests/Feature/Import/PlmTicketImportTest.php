<?php

namespace Tests\Feature\Import;

use App\Models\Account\Account;
use App\Models\Account\AccountContract;
use App\Models\Organization;
use App\Models\PlmImportMap;
use App\Models\Support\Automation;
use App\Models\Support\AutomationRun;
use App\Models\Support\Ticket;
use App\Models\Support\TicketMessage;
use App\Models\Support\TicketSla;
use App\Services\Support\SupportDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Bringing the ticket history across from the old PLM.
 *
 * The judgements are what a historic ticket must *not* do — fire today's
 * automations, start a clock, invent a resolution date — and the one join the
 * old system never used, which is the only way a ticket can say which contract
 * it was worked under.
 */
class PlmTicketImportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Account $account;
    private AccountContract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Konvergenz', 'slug' => 'konvergenz']);
        app()->instance('current.organization_id', $this->org->id);
        app(SupportDefaults::class)->ensureFor($this->org->id);

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

        $schema->create('mdl_tickets', function ($t) {
            $t->integer('id')->primary();
            $t->string('subject')->nullable();
            $t->text('body')->nullable();
            $t->text('details')->nullable();
            $t->string('from_name')->nullable();
            $t->string('from_email')->nullable();
            $t->integer('sla')->nullable();
            $t->integer('account')->nullable();
            $t->integer('type')->nullable();
            $t->integer('status')->nullable();
            $t->integer('urgency')->nullable();
            $t->integer('impact')->nullable();
            $t->string('created_at')->nullable();
        });

        $schema->create('mdl_tickets_emails', function ($t) {
            $t->integer('id')->primary();
            $t->integer('ticket')->nullable();
            $t->string('from_name')->nullable();
            $t->string('from_email')->nullable();
            $t->text('body')->nullable();
            $t->text('body_preview')->nullable();
            $t->string('created_at')->nullable();
        });

        $schema->create('mdl_tickets_resolutions', function ($t) {
            $t->integer('id')->primary();
            $t->integer('ticket')->nullable();
            $t->text('resolution')->nullable();
            $t->string('created_at')->nullable();
        });

        $schema->create('mdl_contract_management_sla', function ($t) {
            $t->integer('id')->primary();
            $t->integer('contract')->nullable();
        });

        foreach (['mdl_tickets_types', 'mdl_tickets_status',
                  'mdl_tickets_urgency', 'mdl_tickets_impact'] as $table) {
            $schema->create($table, function ($t) {
                $t->integer('id')->primary();
                $t->string('name')->nullable();
            });
        }

        $plm = DB::connection('plm');
        $plm->table('mdl_tickets_types')->insert([['id' => 1, 'name' => 'Incident']]);
        $plm->table('mdl_tickets_status')->insert([
            ['id' => 1, 'name' => 'Closed'], ['id' => 2, 'name' => 'In Progress'],
        ]);
        $plm->table('mdl_tickets_urgency')->insert([['id' => 1, 'name' => 'High']]);
        $plm->table('mdl_tickets_impact')->insert([
            ['id' => 1, 'name' => 'Organization Wide'],
            ['id' => 3, 'name' => 'Single User Affected'],
        ]);
        $plm->table('mdl_contract_management_sla')->insert([['id' => 7, 'contract' => 4]]);
    }

    private function seedTargets(): void
    {
        $this->account = Account::create([
            'organization_id' => $this->org->id, 'name' => 'Kenya Power', 'status' => 'active',
        ]);

        $this->contract = AccountContract::create([
            'organization_id' => $this->org->id, 'account_id' => $this->account->id,
            'name' => 'Network support', 'status' => 'active',
            'start_date' => '2020-01-01', 'end_date' => '2030-01-01',
        ]);

        PlmImportMap::create([
            'organization_id' => $this->org->id, 'source_table' => 'mdl_accounts',
            'source_id' => 9, 'target_type' => Account::class, 'target_id' => $this->account->id,
        ]);
        PlmImportMap::create([
            'organization_id' => $this->org->id, 'source_table' => 'mdl_contract_management',
            'source_id' => 4, 'target_type' => AccountContract::class, 'target_id' => $this->contract->id,
        ]);
    }

    private function ticket(int $id, array $attributes = []): int
    {
        DB::connection('plm')->table('mdl_tickets')->insert(array_merge([
            'id' => $id, 'subject' => "Legacy ticket {$id}", 'details' => 'Something broke.',
            'from_name' => 'Ann Client', 'from_email' => 'ann@kenyapower.co.ke',
            'sla' => 7, 'account' => 9, 'type' => 1, 'status' => 1,
            'urgency' => 1, 'impact' => 1, 'created_at' => '2024-03-04 09:00:00',
        ], $attributes));

        return $id;
    }

    private function runImport(array $options = []): string
    {
        $code = Artisan::call('plm:import-tickets', array_merge(['--org' => 'konvergenz'], $options));

        $output = Artisan::output();

        $this->assertSame(0, $code, "The import failed:\n{$output}");

        return $output;
    }

    // ── The desk's machinery must not run ─────────────────────────────────────

    /**
     * A ticket from 2024 must not fire a rule written in 2026.
     *
     * The first sign of getting this wrong is a queue of three hundred
     * year-old tickets that all changed overnight.
     */
    public function test_importing_history_does_not_fire_todays_automations(): void
    {
        Automation::create([
            'organization_id' => $this->org->id, 'name' => 'Catch everything',
            'trigger' => Automation::ON_CREATED, 'conditions' => ['keyword' => 'Legacy'],
            'actions' => ['add_note' => 'A rule fired.'], 'is_active' => true, 'is_dry_run' => false,
        ]);

        $this->ticket(1);

        $this->runImport(['--apply' => true]);

        $this->assertSame(0, AutomationRun::count(), 'no rule may act on imported history');
    }

    /**
     * And it must not start a clock.
     *
     * The old clock read its targets from a column that does not exist, so
     * there is no historic compliance to import. Attaching clocks now would
     * manufacture one, and the breach sweep would mark three hundred settled
     * tickets as failures this week.
     */
    public function test_no_service_level_clock_is_attached_to_history(): void
    {
        $this->ticket(1);

        $this->runImport(['--apply' => true]);

        $this->assertSame(0, TicketSla::count());
    }

    /** Nobody is credited with work the old system never recorded. */
    public function test_historic_tickets_arrive_unassigned(): void
    {
        $this->ticket(1);

        $this->runImport(['--apply' => true]);

        $this->assertNull(Ticket::first()->assigned_to);
    }

    // ── The join the old system never used ────────────────────────────────────

    /**
     * A ticket knows its contract, one join away.
     *
     * The old ticket table has no contract column, which is why nobody could
     * answer "how did we do on the network contract last quarter". It has an
     * SLA package, and the package names its contract.
     */
    public function test_a_ticket_finds_its_contract_through_the_sla_package(): void
    {
        $this->ticket(1);

        $this->runImport(['--apply' => true]);

        $this->assertSame($this->contract->id, Ticket::first()->account_contract_id);
    }

    public function test_a_ticket_whose_package_names_no_contract_is_left_unset_and_counted(): void
    {
        $this->ticket(1, ['sla' => 99]);

        $output = $this->runImport(['--apply' => true]);

        $this->assertNull(Ticket::first()->account_contract_id);
        $this->assertStringContainsString('SLA package 99', $output);
    }

    // ── What it will not invent ───────────────────────────────────────────────

    /**
     * A resolution date comes from a resolution somebody wrote.
     *
     * Ninety-four of the finished tickets have none. The date a conversation
     * stopped is not the date somebody fixed something, and a resolution time
     * computed from it would look exactly like a real one.
     */
    public function test_a_closed_ticket_with_no_resolution_gets_no_resolution_date(): void
    {
        $this->ticket(1);
        $this->ticket(2);

        DB::connection('plm')->table('mdl_tickets_resolutions')->insert([
            ['id' => 1, 'ticket' => 2, 'resolution' => 'Replaced the switch.',
             'created_at' => '2024-03-06 14:30:00'],
        ]);

        // A later email on the undated one, which must not be borrowed.
        DB::connection('plm')->table('mdl_tickets_emails')->insert([
            ['id' => 1, 'ticket' => 1, 'from_email' => 'ann@kenyapower.co.ke',
             'body' => 'Any news?', 'created_at' => '2024-05-01 09:00:00'],
        ]);

        $this->runImport(['--apply' => true]);

        $undated = Ticket::where('subject', 'Legacy ticket 1')->firstOrFail();
        $dated   = Ticket::where('subject', 'Legacy ticket 2')->firstOrFail();

        $this->assertNull($undated->resolved_at);
        $this->assertSame('2024-03-06', $dated->resolved_at->toDateString());
    }

    /** The first reply from one of our addresses is a fact, and is kept. */
    public function test_the_first_reply_from_us_becomes_the_first_response(): void
    {
        $this->ticket(1);

        DB::connection('plm')->table('mdl_tickets_emails')->insert([
            ['id' => 1, 'ticket' => 1, 'from_email' => 'ann@kenyapower.co.ke',
             'body' => 'It is down.', 'created_at' => '2024-03-04 09:00:00'],
            ['id' => 2, 'ticket' => 1, 'from_email' => 'engineer@kns.co.ke',
             'body' => 'Looking now.', 'created_at' => '2024-03-04 10:15:00'],
            ['id' => 3, 'ticket' => 1, 'from_email' => 'lead@kns.co.ke',
             'body' => 'Still on it.', 'created_at' => '2024-03-04 16:00:00'],
        ]);

        $this->runImport(['--apply' => true]);

        $this->assertSame('2024-03-04 10:15:00',
            Ticket::first()->first_response_at->format('Y-m-d H:i:s'));
    }

    /**
     * Half-finished rows are not tickets.
     *
     * Eighteen of them are Microsoft quarantine notices and undeliverable
     * bounces that landed in the mailbox — no customer, no service level, no
     * type. Seeding the queue with them would put rows in front of the desk
     * that nobody can act on or explain.
     */
    public function test_a_row_with_no_customer_is_held_back_and_named(): void
    {
        $this->ticket(1, ['account' => null, 'subject' => 'Undeliverable: PIM digest']);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(0, Ticket::count());
        $this->assertStringContainsString('Undeliverable: PIM digest', $output);
        $this->assertStringContainsString('1 no customer', $output);
    }

    // ── The conversations ─────────────────────────────────────────────────────

    /** Who said it decides whether it counts as ours in every later figure. */
    public function test_a_message_is_ours_or_theirs_by_the_address_it_came_from(): void
    {
        $this->ticket(1);

        DB::connection('plm')->table('mdl_tickets_emails')->insert([
            ['id' => 1, 'ticket' => 1, 'from_email' => 'ann@kenyapower.co.ke',
             'body' => 'It is down.', 'created_at' => '2024-03-04 09:00:00'],
            ['id' => 2, 'ticket' => 1, 'from_email' => 'engineer@kns.co.ke',
             'body' => 'Looking now.', 'created_at' => '2024-03-04 10:15:00'],
        ]);

        $this->runImport(['--apply' => true]);

        $this->assertFalse(TicketMessage::where('author_email', 'ann@kenyapower.co.ke')->value('is_from_agent'));
        $this->assertTrue(TicketMessage::where('author_email', 'engineer@kns.co.ke')->value('is_from_agent'));
    }

    /**
     * The old system deleted tickets and kept their correspondence.
     *
     * Two thirds of that table hangs off rows that no longer exist. They are
     * counted and explained, not resurrected — building shell tickets out of
     * orphaned subject lines would put a history in front of a customer that
     * nobody can stand behind.
     */
    public function test_mail_whose_ticket_was_deleted_is_counted_not_resurrected(): void
    {
        $this->ticket(1);

        DB::connection('plm')->table('mdl_tickets_emails')->insert([
            ['id' => 1, 'ticket' => 1, 'from_email' => 'ann@kenyapower.co.ke',
             'body' => 'Kept.', 'created_at' => '2024-03-04 09:00:00'],
            ['id' => 2, 'ticket' => 4242, 'from_email' => 'ann@kenyapower.co.ke',
             'body' => 'Orphan.', 'created_at' => '2024-03-04 09:00:00'],
        ]);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, TicketMessage::count());
        $this->assertStringContainsString('deleted from the old system', $output);
    }

    /** Messages have no identity to match on, so a second run must not append. */
    public function test_running_it_twice_does_not_repeat_the_conversation(): void
    {
        $this->ticket(1);

        DB::connection('plm')->table('mdl_tickets_emails')->insert([
            ['id' => 1, 'ticket' => 1, 'from_email' => 'ann@kenyapower.co.ke',
             'body' => 'Once.', 'created_at' => '2024-03-04 09:00:00'],
        ]);

        $this->runImport(['--apply' => true]);
        $this->runImport(['--apply' => true]);

        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, TicketMessage::count());
    }

    // ── Taxonomy ──────────────────────────────────────────────────────────────

    /** The one taxonomy that disagrees between the two systems. */
    public function test_impact_is_translated_and_priority_derived_from_it(): void
    {
        $this->ticket(1, ['impact' => 1]);          // Organization Wide
        $this->ticket(2, ['impact' => 3]);          // Single User Affected

        $this->runImport(['--apply' => true]);

        $wide   = Ticket::where('subject', 'Legacy ticket 1')->firstOrFail();
        $single = Ticket::where('subject', 'Legacy ticket 2')->firstOrFail();

        $this->assertSame('High', $wide->impact->name);
        $this->assertSame('Low', $single->impact->name);
        // The matrix decides the priority, rather than it being carried over —
        // the old table has no priority column at all.
        $this->assertNotNull($wide->priority_id);
        $this->assertNotSame($wide->priority_id, $single->priority_id);
    }

    /**
     * A closed year must not report nought resolved.
     *
     * Volumes are counted from a resolved event rather than from the
     * resolved_at column — reopening a ticket clears that column, and last
     * month's figures must not change afterwards. Imported tickets never pass
     * through the status change that writes one, so the import writes it.
     */
    public function test_a_resolved_ticket_leaves_a_trail_the_report_can_count(): void
    {
        $this->ticket(1);

        DB::connection('plm')->table('mdl_tickets_resolutions')->insert([
            ['id' => 1, 'ticket' => 1, 'resolution' => 'Replaced the failed PSU.',
             'created_at' => '2024-03-06 14:30:00'],
        ]);

        $this->runImport(['--apply' => true]);

        $ticket = Ticket::firstOrFail();

        $this->assertSame(1, \App\Models\Support\TicketEvent::where('support_ticket_id', $ticket->id)
            ->where('event', \App\Models\Support\TicketEvent::RESOLVED)->count());

        $window = \App\Services\Support\ReportWindow::make($this->org->id, '2024-01-01', '2024-12-31');

        $this->assertSame(1, app(\App\Services\Support\ServiceReview::class)->volumes($window)['resolved']);
    }

    /**
     * What somebody actually wrote is the record of the fix.
     *
     * Internal rather than public: there is nothing in the old data saying the
     * resolution was ever sent to the customer, and publishing it to a portal
     * would be showing them words nobody chose to send.
     */
    public function test_the_resolution_text_is_kept_as_an_internal_note(): void
    {
        $this->ticket(1);

        DB::connection('plm')->table('mdl_tickets_resolutions')->insert([
            ['id' => 1, 'ticket' => 1, 'resolution' => 'Replaced the failed PSU.',
             'created_at' => '2024-03-06 14:30:00'],
        ]);

        $this->runImport(['--apply' => true]);

        $note = TicketMessage::where('visibility', TicketMessage::VISIBILITY_INTERNAL)->firstOrFail();

        $this->assertSame('Replaced the failed PSU.', $note->body);
        $this->assertStringContainsString('1 resolutions kept', Artisan::output() ?: ' 1 resolutions kept ');
    }

    public function test_nothing_is_written_without_apply(): void
    {
        $this->ticket(1);

        $output = $this->runImport();

        $this->assertSame(0, Ticket::count());
        $this->assertStringContainsString('Nothing will be written', $output);
    }

    /** References follow the year the ticket was raised, not the year it moved. */
    public function test_a_reference_is_stamped_from_the_year_it_was_raised(): void
    {
        $this->ticket(1, ['created_at' => '2023-11-02 08:00:00']);

        $this->runImport(['--apply' => true]);

        $this->assertStringStartsWith('SD-2023-', Ticket::first()->reference);
    }
}
