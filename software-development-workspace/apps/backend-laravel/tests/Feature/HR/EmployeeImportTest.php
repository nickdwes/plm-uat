<?php

namespace Tests\Feature\HR;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Bringing people in from a spreadsheet.
 *
 * A bulk import is worse than typing when it quietly invents things — three
 * spellings of one department becoming three departments, a row half-created
 * because a date could not be read. Most of what follows is about refusing to
 * do that.
 */
class EmployeeImportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Konvergenz', 'slug' => 'konvergenz']);
        app()->instance('current.organization_id', $this->org->id);

        $this->dept = Department::create([
            'organization_id' => $this->org->id, 'name' => 'NetSec', 'code' => 'NS', 'is_active' => true,
        ]);
    }

    private function signIn(array $permissions = null): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'HR', 'scope' => 'organization',
            'permissions' => $permissions ?? [
                Permission::EMPLOYEES_VIEW, Permission::EMPLOYEES_CREATE,
                Permission::EMPLOYEES_UPDATE, Permission::EMPLOYEES_VIEW_SENSITIVE,
            ],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    /** A sheet, as somebody would actually hand one over. */
    private function sheet(array $headings, array $rows): UploadedFile
    {
        $book  = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray($headings, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'people.xlsx', null, null, true);
    }

    private function submit(UploadedFile $file, array $mapping, bool $apply = false): array
    {
        return $this->post("/api/v1/employees/import/run?org={$this->org->slug}", [
            'file' => $file,
            'mapping' => json_encode($mapping),
            'apply' => $apply,
        ])->assertOk()->json('data');
    }

    private const HEAD = ['First Name', 'Last Name', 'Work Email', 'Employment Type', 'Date Joined', 'Department'];

    private const MAP = [
        'First Name' => 'first_name', 'Last Name' => 'last_name', 'Work Email' => 'work_email',
        'Employment Type' => 'employment_type', 'Date Joined' => 'date_hired', 'Department' => 'department',
    ];

    // ── Inspecting ────────────────────────────────────────────────────────

    public function test_it_reads_the_headings_and_guesses_the_mapping(): void
    {
        $this->signIn();

        $body = $this->post("/api/v1/employees/import/inspect?org={$this->org->slug}", [
            'file' => $this->sheet(self::HEAD, [['A', 'B', 'a@kns.co.ke', 'full_time', '2024-01-01', 'NetSec']]),
        ])->assertOk()->json('data');

        $this->assertSame(1, $body['rows']);
        $this->assertSame('first_name', $body['suggested']['First Name']);
        $this->assertSame('date_hired', $body['suggested']['Date Joined']);
    }

    // ── Rehearsing ────────────────────────────────────────────────────────

    public function test_it_writes_nothing_without_apply(): void
    {
        $this->signIn();

        $report = $this->submit(
            $this->sheet(self::HEAD, [['James', 'Kinyanjui', 'j@kns.co.ke', 'full_time', '2024-01-01', 'NetSec']]),
            self::MAP,
        );

        $this->assertFalse($report['applied']);
        $this->assertSame(1, $report['created']);
        $this->assertSame(0, Employee::count());
    }

    public function test_apply_creates_the_person(): void
    {
        $this->signIn();

        $report = $this->submit(
            $this->sheet(self::HEAD, [['James', 'Kinyanjui', 'j@kns.co.ke', 'full_time', '2024-01-01', 'NetSec']]),
            self::MAP,
            apply: true,
        );

        $this->assertSame(1, $report['created']);

        $person = Employee::firstOrFail();
        $this->assertSame('James', $person->first_name);
        $this->assertSame($this->dept->id, $person->department_id);
        $this->assertNotEmpty($person->employee_number);
    }

    /** A second run corrects rather than duplicates. */
    public function test_running_twice_updates_instead_of_creating_again(): void
    {
        $this->signIn();

        $file = fn ($last) => $this->sheet(self::HEAD, [['James', $last, 'j@kns.co.ke', 'full_time', '2024-01-01', 'NetSec']]);

        $this->submit($file('Kinyanjui'), self::MAP, apply: true);
        $report = $this->submit($file('Kinyanjui-Mwangi'), self::MAP, apply: true);

        $this->assertSame(0, $report['created']);
        $this->assertSame(1, $report['updated']);
        $this->assertSame(1, Employee::count());
        $this->assertSame('Kinyanjui-Mwangi', Employee::first()->last_name);
    }

    public function test_it_matches_on_employee_number_before_email(): void
    {
        $this->signIn();

        $existing = Employee::create([
            'employee_number' => 'EMP-00042', 'primary_organization_id' => $this->org->id,
            'first_name' => 'Old', 'last_name' => 'Name', 'employment_type' => 'permanent',
            'date_hired' => '2020-01-01', 'status' => 'active', 'work_email' => 'old@kns.co.ke',
        ]);

        $report = $this->submit(
            $this->sheet(['Employee No.', 'First Name', 'Work Email'], [['EMP-00042', 'New', 'different@kns.co.ke']]),
            ['Employee No.' => 'employee_number', 'First Name' => 'first_name', 'Work Email' => 'work_email'],
            apply: true,
        );

        $this->assertSame(1, $report['updated']);
        $this->assertSame('New', $existing->fresh()->first_name);
    }

    // ── Refusing to invent ────────────────────────────────────────────────

    /**
     * The failure this whole design is built around: a sheet with three
     * spellings of one department must not become three departments.
     */
    public function test_an_unknown_department_is_named_and_counted_never_created(): void
    {
        $this->signIn();

        $report = $this->submit(
            $this->sheet(self::HEAD, [
                ['A', 'One', 'a@kns.co.ke', 'full_time', '2024-01-01', 'Network Security'],
                ['B', 'Two', 'b@kns.co.ke', 'full_time', '2024-01-01', 'Network Security'],
            ]),
            self::MAP,
            apply: true,
        );

        $this->assertSame(1, Department::count(), 'The import created a department.');
        $this->assertSame(2, $report['unmatched']['department']['Network Security']);

        // The people still land — only the department they named is unset.
        $this->assertSame(2, $report['created']);
        $this->assertNull(Employee::first()->department_id);
    }

    /** Spelling and spacing do not make a second department. */
    public function test_a_department_matches_regardless_of_case_and_spacing(): void
    {
        $this->signIn();

        $this->submit(
            $this->sheet(self::HEAD, [['A', 'One', 'a@kns.co.ke', 'full_time', '2024-01-01', ' netsec ']]),
            self::MAP,
            apply: true,
        );

        $this->assertSame($this->dept->id, Employee::first()->department_id);
    }

    // ── Refusing to half-create ───────────────────────────────────────────

    public function test_a_new_person_without_a_hire_date_is_refused_and_named(): void
    {
        $this->signIn();

        $report = $this->submit(
            $this->sheet(self::HEAD, [['James', 'Kinyanjui', 'j@kns.co.ke', 'full_time', '', 'NetSec']]),
            self::MAP,
            apply: true,
        );

        $this->assertSame(0, $report['created']);
        $this->assertSame(1, $report['skipped']);
        $this->assertSame(2, $report['problems'][0]['row'], 'The row number must be the one in the sheet.');
        $this->assertStringContainsString('Date Joined', $report['problems'][0]['reason']);
        $this->assertSame(0, Employee::count());
    }

    public function test_an_unreadable_employment_type_is_refused_rather_than_guessed(): void
    {
        $this->signIn();

        $report = $this->submit(
            $this->sheet(self::HEAD, [['James', 'K', 'j@kns.co.ke', 'permanent-ish', '2024-01-01', 'NetSec']]),
            self::MAP,
            apply: true,
        );

        $this->assertSame(1, $report['skipped']);
        $this->assertStringContainsString('Employment Type', $report['problems'][0]['reason']);
    }

    /** An update need not restate everything a create requires. */
    public function test_an_update_does_not_need_the_required_fields_again(): void
    {
        $this->signIn();

        Employee::create([
            'employee_number' => 'EMP-00042', 'primary_organization_id' => $this->org->id,
            'first_name' => 'James', 'last_name' => 'K', 'employment_type' => 'permanent',
            'date_hired' => '2020-01-01', 'status' => 'active',
        ]);

        $report = $this->submit(
            $this->sheet(['Employee No.', 'Phone'], [['EMP-00042', '0700000000']]),
            ['Employee No.' => 'employee_number', 'Phone' => 'phone'],
            apply: true,
        );

        $this->assertSame(1, $report['updated']);
        $this->assertSame(0, $report['skipped']);
        $this->assertSame('0700000000', Employee::first()->phone);
    }

    /**
     * A phone number written as +254… is a number to a spreadsheet, and the
     * plus is gone before anything here sees the file. Recorded as a test
     * because it looks like an import bug and is not one — the column has to
     * be formatted as text in the sheet, which is what the screen says.
     */
    public function test_a_leading_plus_is_lost_by_the_spreadsheet_not_by_the_import(): void
    {
        $this->signIn();

        Employee::create([
            'employee_number' => 'EMP-00042', 'primary_organization_id' => $this->org->id,
            'first_name' => 'James', 'last_name' => 'K', 'employment_type' => 'permanent',
            'date_hired' => '2020-01-01', 'status' => 'active',
        ]);

        $this->submit(
            $this->sheet(['Employee No.', 'Phone'], [['EMP-00042', '+254700000000']]),
            ['Employee No.' => 'employee_number', 'Phone' => 'phone'],
            apply: true,
        );

        $this->assertSame('254700000000', Employee::first()->phone);
    }

    /** Excel hands back a serial for a real date cell and a string for a typed one. */
    public function test_it_reads_dates_written_either_way(): void
    {
        $this->signIn();

        $report = $this->submit(
            $this->sheet(self::HEAD, [
                ['A', 'One', 'a@kns.co.ke', 'full_time', '15/03/2024', 'NetSec'],
                ['B', 'Two', 'b@kns.co.ke', 'full_time', 45366, 'NetSec'],
            ]),
            self::MAP,
            apply: true,
        );

        $this->assertSame(2, $report['created']);
        $this->assertSame('2024-03-15', Employee::where('first_name', 'A')->first()->date_hired->toDateString());
    }

    // ── Permission ────────────────────────────────────────────────────────

    /** An import creates and updates, so holding one of the two is not enough. */
    public function test_it_needs_both_create_and_update(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW, Permission::EMPLOYEES_CREATE]);

        $this->post("/api/v1/employees/import/run?org={$this->org->slug}", [
            'file' => $this->sheet(self::HEAD, [['A', 'B', 'a@kns.co.ke', 'full_time', '2024-01-01', 'NetSec']]),
            'mapping' => json_encode(self::MAP),
        ])->assertForbidden();
    }

    /** Mapping a column somebody may not write must not write it. */
    public function test_a_mapping_naming_a_forbidden_field_is_dropped(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW, Permission::EMPLOYEES_CREATE, Permission::EMPLOYEES_UPDATE]);

        $this->submit(
            $this->sheet(['First Name', 'Last Name', 'Employment Type', 'Date Joined', 'Basic Salary'],
                [['James', 'K', 'full_time', '2024-01-01', '500000']]),
            ['First Name' => 'first_name', 'Last Name' => 'last_name', 'Employment Type' => 'employment_type',
             'Date Joined' => 'date_hired', 'Basic Salary' => 'basic_salary'],
            apply: true,
        );

        $this->assertEqualsWithDelta(0, (float) Employee::first()->basic_salary, 0.01,
            'A salary was written by somebody without the sensitive permission.');
    }

    // ── Housekeeping ──────────────────────────────────────────────────────

    public function test_trailing_blank_rows_are_ignored(): void
    {
        $this->signIn();

        $report = $this->submit(
            $this->sheet(self::HEAD, [
                ['James', 'K', 'j@kns.co.ke', 'full_time', '2024-01-01', 'NetSec'],
                ['', '', '', '', '', ''],
                [null, null, null, null, null, null],
            ]),
            self::MAP,
        );

        $this->assertSame(1, $report['rows_read']);
        $this->assertSame(1, $report['created']);
    }
}
