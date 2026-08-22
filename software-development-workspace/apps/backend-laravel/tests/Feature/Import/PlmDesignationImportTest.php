<?php

namespace Tests\Feature\Import;

use App\Models\Designation;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\PlmImportMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Recreating the job titles the database loss took with it.
 *
 * The old system kept the title as free text on the employee row, so the role
 * list is the set of distinct titles people actually held — there is no
 * designation table over there to copy.
 */
class PlmDesignationImportTest extends TestCase
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

        $schema = Schema::connection('plm');

        $schema->create('mdl_user', function ($t) {
            $t->integer('id')->primary();
            $t->string('email')->nullable();
        });

        $schema->create('mdl_hr_employees', function ($t) {
            $t->integer('id')->primary();
            $t->integer('user');
            $t->string('designation')->nullable();
        });
    }

    /** One legacy person, already imported, holding a title. */
    private function legacyPerson(int $legacyId, string $email, ?string $designation): Employee
    {
        DB::connection('plm')->table('mdl_user')->insert(['id' => $legacyId, 'email' => $email]);
        DB::connection('plm')->table('mdl_hr_employees')->insert([
            'id' => $legacyId, 'user' => $legacyId, 'designation' => $designation,
        ]);

        $employee = Employee::create([
            'employee_number' => 'E' . $legacyId,
            'primary_organization_id' => $this->org->id,
            'first_name' => 'P' . $legacyId, 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01', 'status' => 'active',
        ]);

        PlmImportMap::create([
            'organization_id' => $this->org->id,
            'source_table'    => 'mdl_hr_employees',
            'source_id'       => $legacyId,
            'target_type'     => Employee::class,
            'target_id'       => $employee->id,
        ]);

        return $employee;
    }

    private function import(bool $apply = true): void
    {
        Artisan::call('import:plm-designations', array_filter([
            '--org'   => 'konvergenz',
            '--apply' => $apply ?: null,
        ]));
    }

    // ── The roster ────────────────────────────────────────────────────────

    public function test_it_creates_a_designation_per_distinct_title_and_attaches_the_holders(): void
    {
        $a = $this->legacyPerson(1, 'a@kns.co.ke', 'Network Engineer');
        $b = $this->legacyPerson(2, 'b@kns.co.ke', 'Network Engineer');
        $c = $this->legacyPerson(3, 'c@kns.co.ke', 'Solutions Architect');

        $this->import();

        $this->assertSame(2, Designation::count());

        $engineer = Designation::where('title', 'Network Engineer')->firstOrFail();

        $this->assertSame($engineer->id, $a->fresh()->designation_id);
        $this->assertSame($engineer->id, $b->fresh()->designation_id);
        $this->assertNotSame($engineer->id, $c->fresh()->designation_id);
    }

    /**
     * Two spellings are one job. Creating both would split a team down the
     * middle for a reason nobody could see on screen.
     */
    public function test_titles_differing_only_in_case_or_spacing_become_one(): void
    {
        $a = $this->legacyPerson(1, 'a@kns.co.ke', 'Network Engineer');
        $b = $this->legacyPerson(2, 'b@kns.co.ke', 'network  engineer');
        $c = $this->legacyPerson(3, 'c@kns.co.ke', 'Network Engineer');

        $this->import();

        $this->assertSame(1, Designation::count());

        // The commonest spelling wins, so one stray entry does not decide how
        // the role reads everywhere.
        $this->assertSame('Network Engineer', Designation::first()->title);
        $this->assertSame(
            [Designation::first()->id],
            array_unique([$a->fresh()->designation_id, $b->fresh()->designation_id, $c->fresh()->designation_id]),
        );
    }

    public function test_it_skips_people_outside_our_domains(): void
    {
        $this->legacyPerson(1, 'someone@another-company.com', 'Chief Executive');
        $this->legacyPerson(2, 'b@kns.co.ke', 'Network Engineer');

        $this->import();

        $this->assertSame(['Network Engineer'], Designation::pluck('title')->all());
    }

    public function test_it_ignores_rows_with_no_title(): void
    {
        $this->legacyPerson(1, 'a@kns.co.ke', null);
        $this->legacyPerson(2, 'b@kns.co.ke', '');
        $this->legacyPerson(3, 'c@kns.co.ke', 'Network Engineer');

        $this->import();

        $this->assertSame(1, Designation::count());
    }

    // ── Habits the other importers keep ───────────────────────────────────

    public function test_it_writes_nothing_without_apply(): void
    {
        $person = $this->legacyPerson(1, 'a@kns.co.ke', 'Network Engineer');

        $this->import(apply: false);

        $this->assertSame(0, Designation::count());
        $this->assertNull($person->fresh()->designation_id);
    }

    public function test_a_second_run_adopts_what_the_first_wrote(): void
    {
        $this->legacyPerson(1, 'a@kns.co.ke', 'Network Engineer');

        $this->import();
        $this->import();

        $this->assertSame(1, Designation::count());
    }

    /**
     * A plausible job description nobody wrote is worse than an empty one:
     * it reads as policy.
     */
    public function test_it_invents_no_description_skills_or_certifications(): void
    {
        $this->legacyPerson(1, 'a@kns.co.ke', 'Network Engineer');

        $this->import();

        $role = Designation::firstOrFail();

        $this->assertNull($role->job_description);
        $this->assertNull($role->desired_skills);
        $this->assertNull($role->certifications);
    }
}
