<?php

namespace Tests\Feature\Import;

use App\Models\Account\Account;
use App\Models\Account\AccountContact;
use App\Models\Account\AccountContract;
use App\Models\Organization;
use App\Models\PlmImportMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Bringing the customer master data across from the old PLM.
 *
 * The legacy database is stood up here as tables of the same shape rather than
 * pointed at a real dump: a test that needs 214 megabytes of somebody's
 * production data to run is a test that stops being run.
 *
 * What is tested is the judgement, not the plumbing — duplicates, zeroes that
 * mean "nobody said", contracts that ended years ago, and the promise that
 * running it twice does not double anything.
 */
class PlmAccountImportTest extends TestCase
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
        // Held open, or an in-memory database is discarded between queries.
        DB::connection('plm')->getPdo();

        $this->buildLegacySchema();
        $this->seedLookups();
    }

    // ── The legacy side ───────────────────────────────────────────────────────

    private function buildLegacySchema(): void
    {
        $schema = Schema::connection('plm');

        $schema->create('mdl_accounts', function ($t) {
            $t->integer('id')->primary();
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->string('status')->nullable();
            $t->integer('account_type')->nullable();
            $t->integer('sector')->nullable();
            $t->integer('country')->nullable();
            $t->string('physical_location')->nullable();
            $t->integer('number_of_branches')->nullable();
            $t->integer('number_of_employees')->nullable();
            $t->string('kra_pin')->nullable();
            $t->string('vat')->nullable();
            $t->string('fund_source')->nullable();
            $t->text('details')->nullable();
            $t->text('comments')->nullable();
            $t->string('created_at')->nullable();
        });

        $schema->create('mdl_accounts_contacts', function ($t) {
            $t->integer('id')->primary();
            $t->string('title')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('alternate_phone')->nullable();
            $t->string('designation')->nullable();
            $t->integer('department')->nullable();
            $t->integer('account')->nullable();
            $t->string('created_at')->nullable();
        });

        $schema->create('mdl_contract_management', function ($t) {
            $t->integer('id')->primary();
            $t->string('name')->nullable();
            $t->string('number')->nullable();
            $t->string('project')->nullable();
            $t->integer('account')->nullable();
            $t->string('start_date')->nullable();
            $t->string('end_date')->nullable();
            $t->string('value')->nullable();
            $t->string('created_at')->nullable();
        });

        foreach (['mdl_accounts_types', 'mdl_accounts_sectors', 'mdl_accounts_countries',
                  'mdl_accounts_departments'] as $table) {
            $schema->create($table, function ($t) {
                $t->integer('id')->primary();
                $t->string('name')->nullable();
            });
        }
    }

    private function seedLookups(): void
    {
        $plm = DB::connection('plm');

        $plm->table('mdl_accounts_types')->insert([
            ['id' => 1, 'name' => 'Key account'],
            ['id' => 3, 'name' => 'Developing account'],
        ]);
        $plm->table('mdl_accounts_sectors')->insert([
            ['id' => 1, 'name' => 'BFSI'],
            ['id' => 2, 'name' => 'Public Sector'],
            ['id' => 9, 'name' => 'Nobody Has This One'],
        ]);
        $plm->table('mdl_accounts_countries')->insert([
            ['id' => 1, 'name' => 'Kenya'],
            ['id' => 9, 'name' => 'Atlantis'],
        ]);
        $plm->table('mdl_accounts_departments')->insert([['id' => 1, 'name' => 'ICT']]);

        // Our side's lookups, per organisation, as the seeder makes them.
        foreach (['Key account', 'Developing account'] as $name) {
            DB::table('account_types')->insert([
                'organization_id' => $this->org->id, 'name' => $name,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (['Public Sector', 'BFSI'] as $name) {
            DB::table('account_sectors')->insert([
                'organization_id' => $this->org->id, 'name' => $name,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function account(int $id, array $attributes = []): void
    {
        DB::connection('plm')->table('mdl_accounts')->insert(array_merge([
            'id' => $id, 'name' => "Customer {$id}", 'status' => 'Active',
            'account_type' => 1, 'sector' => 2, 'country' => 1,
            'number_of_branches' => 0, 'number_of_employees' => 0,
            'created_at' => '2023-07-04 01:24:30',
        ], $attributes));
    }

    /** Run it and hand back everything it printed. */
    private function runImport(array $options = []): string
    {
        $code = Artisan::call('plm:import-accounts', array_merge(['--org' => 'konvergenz'], $options));

        $output = Artisan::output();

        $this->assertSame(0, $code, "The import failed:\n{$output}");

        return $output;
    }

    // ── It rehearses ──────────────────────────────────────────────────────────

    /**
     * An import is the least reversible thing anybody runs.
     *
     * Without a rehearsal, "what would this do" is only answerable by doing it.
     */
    public function test_nothing_is_written_without_apply(): void
    {
        $this->account(1);

        $output = $this->runImport();

        $this->assertSame(0, Account::count());
        $this->assertStringContainsString('Nothing will be written', $output);
        $this->assertStringContainsString('1 read', $output);
    }

    public function test_the_rehearsal_reports_the_same_counts_as_the_real_thing(): void
    {
        $this->account(1);
        $this->account(2);

        $rehearsal = $this->runImport();
        $applied   = $this->runImport(['--apply' => true]);

        $this->assertStringContainsString('2 read, 2 created', $rehearsal);
        $this->assertStringContainsString('2 read, 2 created', $applied);
        $this->assertSame(2, Account::count());
    }

    // ── Duplicates ────────────────────────────────────────────────────────────

    /**
     * The same customer, entered twice, months apart.
     *
     * One of the real pairs has a contract filed under each id, so discarding
     * either half loses a contract — and every ticket that resolves through it.
     */
    public function test_two_records_for_one_customer_become_one_account(): void
    {
        $this->account(20, ['name' => 'Central Bank of Kenya']);
        $this->account(54, ['name' => 'Central Bank of Kenya', 'sector' => 1,
            'created_at' => '2023-07-28 12:17:06']);

        $this->runImport(['--apply' => true]);

        $this->assertSame(1, Account::where('name', 'Central Bank of Kenya')->count());
    }

    /** And both legacy ids point at it, so nothing underneath is orphaned. */
    public function test_work_filed_under_either_duplicate_finds_the_same_customer(): void
    {
        $this->account(40, ['name' => 'Kenya Civil Aviation Authority']);
        $this->account(73, ['name' => 'Kenya Civil Aviation Authority']);

        DB::connection('plm')->table('mdl_contract_management')->insert([
            ['id' => 1, 'name' => 'Older deal', 'account' => 40, 'start_date' => '2020-01-01', 'end_date' => '2030-01-01'],
            ['id' => 2, 'name' => 'Newer deal', 'account' => 73, 'start_date' => '2020-01-01', 'end_date' => '2030-01-01'],
        ]);

        $this->runImport(['--apply' => true]);

        $account = Account::where('name', 'Kenya Civil Aviation Authority')->firstOrFail();

        $this->assertSame(2, AccountContract::where('account_id', $account->id)->count(),
            'both contracts must land on the one customer');
    }

    /**
     * Zero is the old system's "nobody said", not a fact.
     *
     * It has no null for a count, so an unfilled account reads as zero
     * employees — and a merge that treats that as a value keeps the empty half
     * and throws away the thousand somebody recorded.
     */
    public function test_a_blank_count_does_not_beat_a_real_one(): void
    {
        $this->account(20, ['name' => 'Central Bank of Kenya']);
        $this->account(54, ['name' => 'Central Bank of Kenya', 'number_of_employees' => 1000]);

        $this->runImport(['--apply' => true]);

        $this->assertSame(1000, Account::where('name', 'Central Bank of Kenya')->value('number_of_employees'));
    }

    /** Where both halves hold a real, different value, a human is told. */
    public function test_a_genuine_disagreement_is_reported_rather_than_decided(): void
    {
        $this->account(20, ['name' => 'Central Bank of Kenya', 'sector' => 2]);
        $this->account(54, ['name' => 'Central Bank of Kenya', 'sector' => 1]);

        $output = $this->runImport(['--apply' => true]);

        $this->assertStringContainsString('sector differs', $output);
        $this->assertStringContainsString('kept "Public Sector"', $output);
        $this->assertStringContainsString('discarded "BFSI"', $output);
    }

    // ── Nothing is guessed ────────────────────────────────────────────────────

    public function test_a_sector_we_do_not_have_is_left_unset_and_named(): void
    {
        $this->account(1, ['sector' => 9]);

        $output = $this->runImport(['--apply' => true]);

        $this->assertNull(Account::first()->account_sector_id);
        $this->assertStringContainsString('Nobody Has This One', $output);
    }

    public function test_a_country_we_cannot_place_is_left_unset_and_named(): void
    {
        $this->account(1, ['country' => 9]);

        $output = $this->runImport(['--apply' => true]);

        $this->assertNull(Account::first()->country);
        $this->assertStringContainsString('Atlantis', $output);
    }

    /**
     * Lookups match by name, never by id.
     *
     * Both systems number their sectors from one and the numbers mean
     * different things. Reusing them would file hospitals under energy.
     */
    public function test_lookups_are_matched_by_name(): void
    {
        $this->account(1, ['sector' => 1]);          // BFSI over there

        $this->runImport(['--apply' => true]);

        $ours = DB::table('account_sectors')
            ->where('organization_id', $this->org->id)->where('name', 'BFSI')->value('id');

        $this->assertSame($ours, Account::first()->account_sector_id);
    }

    // ── Contracts ─────────────────────────────────────────────────────────────

    /**
     * A contract that ended in 2024 is not a support entitlement.
     *
     * The old table had no status column. Importing fifty-five contracts as
     * active would hand those customers cover they stopped paying for — and
     * the SLA clock would honour it.
     */
    public function test_a_contract_that_has_ended_does_not_arrive_active(): void
    {
        $this->account(1);

        DB::connection('plm')->table('mdl_contract_management')->insert([
            ['id' => 1, 'name' => 'Ended', 'account' => 1, 'start_date' => '2020-01-01', 'end_date' => '2024-12-20'],
            ['id' => 2, 'name' => 'Running', 'account' => 1, 'start_date' => '2020-01-01', 'end_date' => '2030-01-01'],
            ['id' => 3, 'name' => 'No end', 'account' => 1, 'start_date' => '2020-01-01', 'end_date' => null],
        ]);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame('expired', AccountContract::where('name', 'Ended')->value('status'));
        $this->assertSame('active', AccountContract::where('name', 'Running')->value('status'));

        /*
         * A contract missing a date is left out and named rather than given
         * one. Inventing a term on somebody's commercial agreement is the one
         * thing this import must never do.
         */
        $this->assertNull(AccountContract::where('name', 'No end')->first());
        $this->assertStringContainsString('has no end date and was left out', $output);
        $this->assertStringContainsString('1 incomplete dates', $output);
    }

    public function test_a_contract_whose_account_did_not_arrive_is_held_back_and_named(): void
    {
        DB::connection('plm')->table('mdl_contract_management')->insert([
            ['id' => 1, 'name' => 'Orphan', 'account' => 999, 'start_date' => '2020-01-01', 'end_date' => '2030-01-01'],
        ]);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(0, AccountContract::count());
        $this->assertStringContainsString('1 orphaned', $output);
        $this->assertStringContainsString('did not come across', $output);
    }

    // ── Contacts ──────────────────────────────────────────────────────────────

    /**
     * With one contact there is nobody else the primary could be.
     *
     * With several there is no fact to derive, so none is chosen — putting a
     * name on correspondence that nobody picked is worse than leaving it unset.
     */
    public function test_a_sole_contact_is_the_primary_and_a_crowd_is_not(): void
    {
        $this->account(1);
        $this->account(2);

        DB::connection('plm')->table('mdl_accounts_contacts')->insert([
            ['id' => 1, 'first_name' => 'Ann', 'last_name' => 'Solo', 'account' => 1, 'department' => 1],
            ['id' => 2, 'first_name' => 'Bob', 'last_name' => 'One', 'account' => 2, 'department' => null],
            ['id' => 3, 'first_name' => 'Cath', 'last_name' => 'Two', 'account' => 2, 'department' => null],
        ]);

        $this->runImport(['--apply' => true]);

        $this->assertTrue(AccountContact::where('first_name', 'Ann')->value('is_primary'));
        $this->assertSame(0, AccountContact::whereIn('first_name', ['Bob', 'Cath'])
            ->where('is_primary', true)->count());
        $this->assertSame('ICT', AccountContact::where('first_name', 'Ann')->value('department'));
    }

    // ── Twice ─────────────────────────────────────────────────────────────────

    /** Imports always get run more than once. */
    public function test_running_it_twice_updates_rather_than_duplicates(): void
    {
        $this->account(1, ['name' => 'Kenya Power']);
        DB::connection('plm')->table('mdl_accounts_contacts')->insert([
            ['id' => 1, 'first_name' => 'Ann', 'last_name' => 'Solo', 'account' => 1],
        ]);
        DB::connection('plm')->table('mdl_contract_management')->insert([
            ['id' => 1, 'name' => 'Deal', 'account' => 1, 'start_date' => '2020-01-01', 'end_date' => '2030-01-01'],
        ]);

        $this->runImport(['--apply' => true]);

        // Somebody fixes a typo in the old system, and it runs again.
        DB::connection('plm')->table('mdl_accounts')->where('id', 1)
            ->update(['name' => 'Kenya Power and Lighting']);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(1, Account::count());
        $this->assertSame(1, AccountContact::count());
        $this->assertSame(1, AccountContract::count());
        $this->assertSame('Kenya Power and Lighting', Account::first()->name);
        $this->assertStringContainsString('0 created, 1 updated', $output);
    }

    public function test_what_became_what_is_recorded(): void
    {
        $this->account(7, ['name' => 'National Social Security Fund']);

        $this->runImport(['--apply' => true]);

        $map = PlmImportMap::where('source_table', 'mdl_accounts')->where('source_id', 7)->firstOrFail();

        $this->assertSame(Account::class, $map->target_type);
        $this->assertSame(Account::first()->id, $map->target_id);
        $this->assertSame('National Social Security Fund', $map->source_snapshot['name']);
    }
}
