<?php

namespace Tests\Feature\HR;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * The people report.
 *
 * An export is the easiest way to take a personnel file out of a building, so
 * most of what follows is about which columns a given viewer can actually
 * obtain — not about spreadsheets.
 */
class PeopleReportTest extends TestCase
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

    private function signIn(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Reporter', 'scope' => 'organization',
            'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    private function employee(array $attrs = []): Employee
    {
        return Employee::create($attrs + [
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'first_name' => 'James', 'last_name' => 'Kinyanjui',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'status' => 'active', 'work_email' => 'j@kns.co.ke',
            'basic_salary' => 200000, 'house_allowance' => 15000,
            'tax_id' => 'A0567659303X',
        ]);
    }

    private function url(string $path, string $query = ''): string
    {
        return "/api/v1/reports/people{$path}?org={$this->org->slug}" . ($query ? "&{$query}" : '');
    }

    // ── Who may put what in a report ──────────────────────────────────────

    public function test_the_catalogue_hides_pay_and_statutory_ids_without_the_permission(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW]);

        $keys = collect($this->getJson($this->url('/columns'))->assertOk()->json('data.columns'))
            ->pluck('key');

        $this->assertContains('full_name', $keys);
        $this->assertNotContains('basic_salary', $keys);
        $this->assertNotContains('tax_id', $keys);
        $this->assertFalse($this->getJson($this->url('/columns'))->json('data.sees_sensitive'));
    }

    public function test_the_catalogue_offers_them_with_the_permission(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW, Permission::EMPLOYEES_VIEW_SENSITIVE]);

        $keys = collect($this->getJson($this->url('/columns'))->assertOk()->json('data.columns'))
            ->pluck('key');

        $this->assertContains('basic_salary', $keys);
        $this->assertContains('tax_id', $keys);
    }

    /**
     * The picker is a convenience. Naming the column by hand must not get it,
     * or the whole gate is decoration.
     */
    public function test_asking_for_a_forbidden_column_by_hand_does_not_return_it(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW]);
        $this->employee();

        $body = $this->getJson($this->url('/preview', 'columns=full_name,basic_salary,tax_id'))
            ->assertOk()->json('data');

        $this->assertSame(['full_name'], $body['columns']);
        $this->assertSame([['James Kinyanjui']], $body['rows']);
    }

    /** Asking for nothing usable falls back to a report, not an empty sheet. */
    public function test_a_request_naming_only_forbidden_columns_falls_back_to_the_defaults(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW]);
        $this->employee();

        $columns = $this->getJson($this->url('/preview', 'columns=basic_salary,tax_id'))
            ->assertOk()->json('data.columns');

        $this->assertContains('full_name', $columns);
        $this->assertNotContains('basic_salary', $columns);
    }

    // ── Shape ─────────────────────────────────────────────────────────────

    /** Configurable means arrangement as well as choice. */
    public function test_columns_come_back_in_the_order_they_were_asked_for(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW]);
        $this->employee();

        $body = $this->getJson($this->url('/preview', 'columns=department,full_name,employee_number'))
            ->assertOk()->json('data');

        $this->assertSame(['department', 'full_name', 'employee_number'], $body['columns']);
        $this->assertSame(['Department', 'Name', 'Employee No.'], $body['headings']);
        $this->assertSame('NetSec', $body['rows'][0][0]);
    }

    public function test_gross_pay_is_the_sum_of_the_parts(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW, Permission::EMPLOYEES_VIEW_SENSITIVE]);
        $this->employee();

        $rows = $this->getJson($this->url('/preview', 'columns=gross_pay'))->assertOk()->json('data.rows');

        $this->assertEqualsWithDelta(215000, $rows[0][0], 0.01);
    }

    public function test_drafts_are_left_out_the_way_the_directory_leaves_them_out(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW]);
        $this->employee();
        $this->employee(['status' => 'draft']);

        $this->assertSame(1, $this->getJson($this->url('/preview'))->assertOk()->json('data.total'));
    }

    // ── The file ──────────────────────────────────────────────────────────

    public function test_the_export_is_a_readable_workbook_with_a_heading_row(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW]);
        $this->employee();

        $res = $this->post($this->url('/export'), ['columns' => 'employee_number,full_name']);
        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('content-type'));

        $path = tempnam(sys_get_temp_dir(), 'rep') . '.xlsx';
        file_put_contents($path, $res->streamedContent());

        $sheet = IOFactory::load($path)->getActiveSheet();

        $this->assertSame('Employee No.', $sheet->getCell('A1')->getValue());
        $this->assertSame('Name', $sheet->getCell('B1')->getValue());
        $this->assertSame('James Kinyanjui', $sheet->getCell('B2')->getValue());

        unlink($path);
    }

    /**
     * Every employee the filters match, not the page on screen. A report
     * headed "all employees" that stops at twenty-five is a file somebody
     * plans around.
     */
    public function test_the_export_covers_everybody_not_just_the_preview_page(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW]);

        foreach (range(1, 30) as $i) {
            $this->employee(['first_name' => "P{$i}"]);
        }

        $res = $this->post($this->url('/export'), ['columns' => 'full_name']);
        $res->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'rep') . '.xlsx';
        file_put_contents($path, $res->streamedContent());

        $sheet = IOFactory::load($path)->getActiveSheet();

        // 30 people plus the heading row.
        $this->assertSame(31, $sheet->getHighestDataRow());

        unlink($path);
    }

    /** A salary written as text cannot be summed, and totalling it is the first thing anybody does. */
    public function test_money_lands_as_a_number(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW, Permission::EMPLOYEES_VIEW_SENSITIVE]);
        $this->employee();

        $res = $this->post($this->url('/export'), ['columns' => 'basic_salary', 'password' => 'correct-horse']);
        $res->assertOk();

        $value = $this->openProtected($res->streamedContent(), 'correct-horse')
            ->getActiveSheet()->getCell('A2')->getValue();

        $this->assertIsNumeric($value);
        $this->assertEqualsWithDelta(200000, (float) $value, 0.01);
    }

    // ── Protection ────────────────────────────────────────────────────────

    /**
     * Pay and statutory identifiers cannot leave unprotected. Somebody
     * exporting names and departments should not have to invent a password;
     * somebody exporting salaries should not be able to skip one.
     */
    public function test_a_report_holding_pay_is_refused_without_a_password(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW, Permission::EMPLOYEES_VIEW_SENSITIVE]);
        $this->employee();

        $this->post($this->url('/export'), ['columns' => 'full_name,basic_salary'])
            ->assertStatus(422);
    }

    public function test_a_short_password_is_refused_too(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW, Permission::EMPLOYEES_VIEW_SENSITIVE]);
        $this->employee();

        $this->post($this->url('/export'), ['columns' => 'basic_salary', 'password' => 'abc'])
            ->assertStatus(422);
    }

    public function test_a_report_without_sensitive_columns_needs_no_password(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW]);
        $this->employee();

        $res = $this->post($this->url('/export'), ['columns' => 'full_name,department'])->assertOk();

        $this->assertStringContainsString('spreadsheetml', $res->headers->get('content-type'));
    }

    /** The protection has to be real — the rows must not be readable without it. */
    public function test_the_protected_archive_will_not_open_with_the_wrong_password(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW, Permission::EMPLOYEES_VIEW_SENSITIVE]);
        $this->employee();

        $res = $this->post($this->url('/export'), ['columns' => 'full_name,basic_salary', 'password' => 'correct-horse']);
        $res->assertOk();
        $this->assertSame('application/zip', $res->headers->get('content-type'));

        $path = tempnam(sys_get_temp_dir(), 'rep') . '.zip';
        file_put_contents($path, $res->streamedContent());

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $zip->setPassword('not-the-password');

        $this->assertFalse(
            @$zip->getFromIndex(0),
            'The archive gave up its contents to the wrong password.',
        );

        $zip->close();
        unlink($path);
    }

    /** And the salary must not be sitting in the archive as cleartext. */
    public function test_the_payroll_figures_are_not_readable_in_the_raw_archive(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW, Permission::EMPLOYEES_VIEW_SENSITIVE]);
        $this->employee();

        $raw = $this->post($this->url('/export'), [
            'columns' => 'full_name,basic_salary', 'password' => 'correct-horse',
        ])->streamedContent();

        $this->assertStringNotContainsString('Kinyanjui', $raw);
        $this->assertStringNotContainsString('200000', $raw);
    }

    /** The screen has to be able to say a password is needed before it asks for one. */
    public function test_the_requirement_endpoint_names_the_column_that_forces_a_password(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW, Permission::EMPLOYEES_VIEW_SENSITIVE]);

        $body = $this->getJson($this->url('/requirement', 'columns=full_name,basic_salary'))
            ->assertOk()->json('data');

        $this->assertTrue($body['password_required']);
        $this->assertContains('Basic Salary', $body['because']);

        $plain = $this->getJson($this->url('/requirement', 'columns=full_name'))->assertOk()->json('data');
        $this->assertFalse($plain['password_required']);
    }

    /** Open the encrypted archive the way a recipient would. */
    private function openProtected(string $bytes, string $password): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $archive = tempnam(sys_get_temp_dir(), 'rep') . '.zip';
        file_put_contents($archive, $bytes);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($archive) === true, 'The export was not a readable archive.');
        $zip->setPassword($password);

        $inner = $zip->getNameIndex(0);
        $contents = $zip->getFromName($inner);
        $zip->close();

        $this->assertNotFalse($contents, 'The archive did not open with the right password.');

        $book = tempnam(sys_get_temp_dir(), 'rep') . '.xlsx';
        file_put_contents($book, $contents);

        $spreadsheet = IOFactory::load($book);

        unlink($archive);
        unlink($book);

        return $spreadsheet;
    }

    /** An employee number like 0012 must not lose its leading zeros in Excel. */
    public function test_identifiers_keep_their_leading_zeros(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW]);
        $this->employee(['employee_number' => '0012']);

        $res = $this->post($this->url('/export'), ['columns' => 'employee_number']);
        $path = tempnam(sys_get_temp_dir(), 'rep') . '.xlsx';
        file_put_contents($path, $res->streamedContent());

        $this->assertSame('0012', IOFactory::load($path)->getActiveSheet()->getCell('A2')->getValue());

        unlink($path);
    }

    public function test_a_forbidden_column_never_reaches_the_file(): void
    {
        $this->signIn([Permission::EMPLOYEES_VIEW]);
        $this->employee();

        $res = $this->post($this->url('/export'), ['columns' => 'full_name,basic_salary']);
        $path = tempnam(sys_get_temp_dir(), 'rep') . '.xlsx';
        file_put_contents($path, $res->streamedContent());

        $sheet = IOFactory::load($path)->getActiveSheet();

        $this->assertSame('Name', $sheet->getCell('A1')->getValue());
        $this->assertNull($sheet->getCell('B1')->getValue(), 'Pay reached a report it was not allowed in.');

        unlink($path);
    }
}
