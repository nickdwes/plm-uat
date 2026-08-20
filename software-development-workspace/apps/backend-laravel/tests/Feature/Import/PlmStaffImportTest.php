<?php

namespace Tests\Feature\Import;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\PlmImportMap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Bringing the staff across from the old PLM.
 *
 * The judgements worth defending are which company somebody works for, what
 * happens to the people who have left, and everything the import refuses to
 * invent — a hire date, a reporting line, a department whose name it cannot
 * be sure of.
 */
class PlmStaffImportTest extends TestCase
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

        $schema->create('mdl_user', function ($t) {
            $t->integer('id')->primary();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->string('phone')->nullable();
            $t->string('address')->nullable();
            $t->integer('is_active')->default(1);
        });

        $schema->create('mdl_hr_employees', function ($t) {
            $t->integer('id')->primary();
            $t->integer('user')->nullable();
            $t->integer('bu')->nullable();
            $t->string('designation')->nullable();
            $t->integer('answers_to')->nullable();
            $t->integer('also_answers_to')->nullable();
            $t->integer('employee_type')->nullable();
            $t->integer('gender')->nullable();
            $t->string('employement_date')->nullable();
            $t->string('enrol_date')->nullable();
        });

        foreach (['mdl_bu', 'mdl_hr_etypes'] as $table) {
            $schema->create($table, function ($t) {
                $t->integer('id')->primary();
                $t->string('name')->nullable();
            });
        }

        DB::connection('plm')->table('mdl_hr_etypes')->insert([
            ['id' => 2, 'name' => 'Contract'],
            ['id' => 3, 'name' => 'Permanent'],
        ]);
        DB::connection('plm')->table('mdl_bu')->insert([
            ['id' => 1, 'name' => 'Data Centre & Power '],
            ['id' => 2, 'name' => 'Service Delivery'],
            ['id' => 3, 'name' => 'Project Management'],
            ['id' => 4, 'name' => 'Finance'],
        ]);

        foreach (['Data Centre', 'Service Delivery', 'PMO', 'Projects Management office',
                  'Finance', 'Finance'] as $name) {
            DB::table('departments')->insert([
                'organization_id' => $this->org->id, 'name' => $name,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('designations')->insert([
            'organization_id' => $this->org->id, 'title' => 'Project Manager',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * One person in the old system: a login and an employee record.
     *
     * @return int  the legacy employee id
     */
    private function person(int $id, array $user = [], array $employee = []): int
    {
        DB::connection('plm')->table('mdl_user')->insert(array_merge([
            'id' => $id, 'first_name' => "Person{$id}", 'last_name' => 'Test',
            'email' => "person{$id}@kns.co.ke", 'password' => '$2y$10$notarealhashatall',
            'is_active' => 1,
        ], $user));

        DB::connection('plm')->table('mdl_hr_employees')->insert(array_merge([
            'id' => $id, 'user' => $id, 'employee_type' => 3,
            'employement_date' => '2019-04-01',
        ], $employee));

        return $id;
    }

    private function runImport(array $options = []): string
    {
        $code = Artisan::call('plm:import-staff', array_merge(['--org' => 'konvergenz'], $options));

        $output = Artisan::output();

        $this->assertSame(0, $code, "The import failed:\n{$output}");

        return $output;
    }

    // ── Which company somebody works for ──────────────────────────────────────

    /**
     * Read from the address, because the business unit cannot answer it.
     *
     * "Healthcare Technology & Innovation" holds twenty-seven Tiberbu people,
     * ten Konvergenz and one from Visibility. Tenancy is the boundary the whole
     * application is built on, and putting somebody the wrong side of it is not
     * a tidy-up afterwards.
     */
    public function test_only_our_own_staff_are_imported(): void
    {
        $this->person(1);
        $this->person(2, ['email' => 'someone@tiberbu.com']);
        $this->person(3, ['email' => 'other@canadatel.net']);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(1, Employee::count());
        $this->assertStringContainsString('tiberbu.com — 1', $output);
        $this->assertStringContainsString('canadatel.net — 1', $output);
    }

    // ── Leavers ───────────────────────────────────────────────────────────────

    /**
     * They keep the record and lose the login.
     *
     * Dropping them would leave holes in the reporting lines of the people who
     * remain — somebody still reports to a person who has left.
     */
    public function test_somebody_who_has_left_keeps_a_record_and_gets_no_login(): void
    {
        $this->person(1, ['is_active' => 0, 'email' => 'gone@kns.co.ke']);

        $this->runImport(['--apply' => true]);

        $employee = Employee::firstOrFail();

        $this->assertSame('exited', $employee->status);
        $this->assertNull($employee->user_id);
        $this->assertSame(0, User::where('email', 'gone@kns.co.ke')->count());
    }

    // ── Credentials ───────────────────────────────────────────────────────────

    /**
     * No password comes across. Not one.
     *
     * An import that carries hashes out of a system being retired for its
     * security carries the reason it is being retired.
     */
    public function test_no_legacy_password_survives_the_crossing(): void
    {
        $this->person(1);

        $this->runImport(['--apply' => true]);

        $user = User::where('email', 'person1@kns.co.ke')->firstOrFail();

        $this->assertNotSame('$2y$10$notarealhashatall', $user->password);
        $this->assertFalse(Hash::check('$2y$10$notarealhashatall', $user->password));
        $this->assertSame(User::STATUS_PENDING, $user->account_status);
    }

    /** Somebody already signing in keeps their account and their password. */
    public function test_an_existing_account_is_matched_rather_than_duplicated(): void
    {
        $existing = User::create([
            'name' => 'Already Here', 'email' => 'person1@kns.co.ke',
            'password' => Hash::make('their own password'),
            'account_status' => User::STATUS_ACTIVE,
        ]);

        $this->person(1);

        $this->runImport(['--apply' => true]);

        $this->assertSame(1, User::where('email', 'person1@kns.co.ke')->count());
        $this->assertTrue(Hash::check('their own password', $existing->fresh()->password));
        $this->assertSame($existing->id, Employee::first()->user_id);
    }

    // ── Who answers to whom ───────────────────────────────────────────────────

    /**
     * Built in a second pass, because a tree cannot be built in one.
     *
     * The first person imported answers to somebody who does not exist yet.
     */
    public function test_a_reporting_line_survives_the_manager_being_imported_later(): void
    {
        $this->person(1, [], ['answers_to' => 2]);       // manager comes second
        $this->person(2);

        $this->runImport(['--apply' => true]);

        $junior = Employee::where('work_email', 'person1@kns.co.ke')->firstOrFail();
        $senior = Employee::where('work_email', 'person2@kns.co.ke')->firstOrFail();

        $this->assertSame($senior->id, $junior->reports_to);
    }

    /**
     * A manager at another company leaves the line unset, not guessed.
     *
     * A wrong reporting line is read as fact by leave approval and by every
     * escalation path.
     */
    public function test_a_manager_who_was_not_imported_leaves_the_line_empty(): void
    {
        $this->person(1, [], ['answers_to' => 2]);
        $this->person(2, ['email' => 'boss@tiberbu.com']);

        $output = $this->runImport(['--apply' => true]);

        $this->assertNull(Employee::where('work_email', 'person1@kns.co.ke')->value('reports_to'));
        $this->assertStringContainsString('report to somebody who was not imported', $output);
    }

    /** A second reporting line is what the org chart draws as shared. */
    public function test_a_dotted_line_marks_somebody_as_shared(): void
    {
        $this->person(1, [], ['also_answers_to' => 2]);
        $this->person(2);

        $this->runImport(['--apply' => true]);

        $this->assertTrue(Employee::where('work_email', 'person1@kns.co.ke')->value('is_shared'));
        $this->assertFalse(Employee::where('work_email', 'person2@kns.co.ke')->value('is_shared'));
    }

    // ── What it will not invent ───────────────────────────────────────────────

    /**
     * A hire date cannot be made up.
     *
     * It is what leave accrues from and length of service is counted from, and
     * a plausible one is indistinguishable from a real one once it is filed.
     */
    public function test_somebody_with_no_hire_date_is_named_rather_than_given_one(): void
    {
        $this->person(1, ['first_name' => 'Undated'],
            ['employement_date' => null, 'enrol_date' => null]);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(0, Employee::count());
        $this->assertStringContainsString('Undated', $output);
        $this->assertStringContainsString('has no hire date', $output);
        $this->assertStringContainsString('1 no hire date', $output);
    }

    /** The enrolment date stands in where the employment date is missing. */
    public function test_the_enrolment_date_covers_a_missing_employment_date(): void
    {
        $this->person(1, [], ['employement_date' => null, 'enrol_date' => '2021-06-15']);

        $this->runImport(['--apply' => true]);

        $this->assertSame('2021-06-15', Employee::first()->date_hired->toDateString());
    }

    /**
     * Where the schema forces a guess, the guess is counted out loud.
     *
     * Permanent and contract are near enough a coin flip in the old data, and
     * employment type decides contract end dates and how somebody is paid.
     */
    public function test_a_missing_employment_type_is_defaulted_and_declared(): void
    {
        $this->person(1, [], ['employee_type' => null]);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame('full_time', Employee::first()->employment_type);
        $this->assertStringContainsString('1 people had no employment type', $output);
        $this->assertStringContainsString('before payroll runs', $output);
    }

    // ── Departments ───────────────────────────────────────────────────────────

    /** The old names are longer than ours, so the obvious ones are written out. */
    public function test_a_known_alias_places_somebody_in_the_right_department(): void
    {
        $this->person(1, [], ['bu' => 1]);              // "Data Centre & Power "

        $this->runImport(['--apply' => true]);

        $this->assertSame(
            DB::table('departments')->where('name', 'Data Centre')->value('id'),
            Employee::first()->department_id,
        );
    }

    /**
     * Two of ours could be meant, so neither is chosen.
     *
     * "Project Management" could be PMO or Projects Management office, and
     * picking one silently splits a team in half.
     */
    public function test_an_ambiguous_unit_is_left_unset_and_reported(): void
    {
        $this->person(1, [], ['bu' => 3]);              // "Project Management"

        $output = $this->runImport(['--apply' => true]);

        $this->assertNull(Employee::first()->department_id);
        $this->assertStringContainsString('Project Management', $output);
    }

    /**
     * And where our own reference data has the same name twice, it refuses.
     *
     * Attaching half the finance team to one "Finance" and half to the other
     * is a split nobody would ever spot on screen.
     */
    public function test_a_duplicated_department_name_of_ours_is_refused(): void
    {
        $this->person(1, [], ['bu' => 4]);              // "Finance", and we have two

        $output = $this->runImport(['--apply' => true]);

        $this->assertNull(Employee::first()->department_id);
        $this->assertStringContainsString('more than one by that name', $output);
    }

    // ── The usual promises ────────────────────────────────────────────────────

    public function test_nothing_is_written_without_apply(): void
    {
        $this->person(1);

        $output = $this->runImport();

        $this->assertSame(0, Employee::count());
        $this->assertSame(0, User::where('email', 'person1@kns.co.ke')->count());
        $this->assertStringContainsString('Nothing will be written', $output);
    }

    public function test_running_it_twice_updates_rather_than_duplicates(): void
    {
        $this->person(1, [], ['answers_to' => 2]);
        $this->person(2);

        $this->runImport(['--apply' => true]);

        DB::connection('plm')->table('mdl_user')->where('id', 1)->update(['first_name' => 'Renamed']);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(2, Employee::count());
        $this->assertSame('Renamed', Employee::where('work_email', 'person1@kns.co.ke')->value('first_name'));
        $this->assertStringContainsString('0 created, 2 updated', $output);
    }

    public function test_what_became_whom_is_recorded(): void
    {
        $this->person(7);

        $this->runImport(['--apply' => true]);

        $map = PlmImportMap::where('source_table', 'mdl_hr_employees')->where('source_id', 7)->firstOrFail();

        $this->assertSame(Employee::class, $map->target_type);
        $this->assertSame(Employee::first()->id, $map->target_id);
    }
}
