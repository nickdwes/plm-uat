<?php

namespace Tests\Feature\Import;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\PlmImportMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Bringing the leave history across from the old PLM.
 *
 * Leave is the one module where history is not optional: an entitlement is an
 * annual figure, so a balance worked out without last year's approved leave is
 * wrong from the day it is switched on. Most of what follows is about counting
 * days the way the company actually counted them.
 */
class PlmLeaveImportTest extends TestCase
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

        $schema->create('mdl_hr_leave', function ($t) {
            $t->integer('id')->primary();
            $t->string('status')->nullable();
            $t->string('start_date')->nullable();
            $t->string('end_date')->nullable();
            $t->string('days_type')->nullable();
            $t->text('leave_reason')->nullable();
            $t->text('decline_reason')->nullable();
            $t->string('approved_at')->nullable();
            $t->string('approved_by_email')->nullable();
            $t->string('year')->nullable();
            $t->string('created_at')->nullable();
            $t->integer('employee')->nullable();
            $t->integer('leave_type')->nullable();
            $t->integer('on_behalf')->nullable();
        });

        $schema->create('mdl_leave_days', function ($t) {
            $t->integer('id')->primary();
            $t->integer('leave')->nullable();
            $t->string('day')->nullable();
            $t->string('period')->nullable();
        });

        $schema->create('mdl_leave_types', function ($t) {
            $t->integer('id')->primary();
            $t->string('name')->nullable();
            $t->integer('days')->nullable();
            $t->integer('is_earned')->nullable();
            $t->integer('includes_weekend')->nullable();
            $t->integer('position')->nullable();
        });

        DB::connection('plm')->table('mdl_leave_types')->insert([
            ['id' => 1, 'name' => 'Annual Leave', 'days' => 21, 'is_earned' => 1,
             'includes_weekend' => 0, 'position' => 1],
            ['id' => 2, 'name' => 'Sick Leave', 'days' => 30, 'is_earned' => 0,
             'includes_weekend' => 1, 'position' => 2],
            ['id' => 5, 'name' => 'Study Leave', 'days' => 5, 'is_earned' => 0,
             'includes_weekend' => 1, 'position' => 5],
            ['id' => 8, 'name' => 'Unpaid Leave', 'days' => 90, 'is_earned' => 0,
             'includes_weekend' => 1, 'position' => 8],
        ]);

        // Ours, with the double space the seeded data actually carries.
        LeaveType::create([
            'organization_id' => $this->org->id, 'name' => 'Annual Leave', 'code' => 'AL',
            'days_per_year' => 21, 'is_paid' => true, 'carry_forward' => true,
            'requires_approval' => true, 'is_active' => true, 'counting_method' => 'working',
        ]);
        LeaveType::create([
            'organization_id' => $this->org->id, 'name' => 'Sick  Leave', 'code' => 'SL',
            'days_per_year' => 30, 'is_paid' => true, 'carry_forward' => false,
            'requires_approval' => true, 'is_active' => true, 'counting_method' => 'calendar',
        ]);
    }

    private function staff(int $legacyId, string $email = null): Employee
    {
        $employee = Employee::create([
            'employee_number' => "EMP-{$legacyId}", 'primary_organization_id' => $this->org->id,
            'first_name' => 'Ann', 'last_name' => "Number{$legacyId}",
            'work_email' => $email ?? "ann{$legacyId}@kns.co.ke",
            'employment_type' => 'full_time', 'status' => 'active', 'date_hired' => '2020-01-01',
        ]);

        PlmImportMap::create([
            'organization_id' => $this->org->id, 'source_table' => 'mdl_hr_employees',
            'source_id' => $legacyId, 'target_type' => Employee::class, 'target_id' => $employee->id,
        ]);

        return $employee;
    }

    private function leave(int $id, array $attributes = [], array $days = []): void
    {
        DB::connection('plm')->table('mdl_hr_leave')->insert(array_merge([
            'id' => $id, 'employee' => 42, 'leave_type' => 1, 'status' => 'Approved',
            'start_date' => '2024-06-03', 'end_date' => '2024-06-07', 'days_type' => 'Range',
            'leave_reason' => '<p>Family visit</p>', 'created_at' => '2024-05-20 09:00:00',
            'year' => '2024',
        ], $attributes));

        foreach ($days as $i => [$day, $period]) {
            DB::connection('plm')->table('mdl_leave_days')->insert([
                'id' => $id * 100 + $i, 'leave' => $id, 'day' => $day, 'period' => $period,
            ]);
        }
    }

    private function runImport(array $options = []): string
    {
        $code = Artisan::call('plm:import-leave', array_merge(['--org' => 'konvergenz'], $options));

        $output = Artisan::output();

        $this->assertSame(0, $code, "The import failed:\n{$output}");

        return $output;
    }

    // ── Counting days ─────────────────────────────────────────────────────────

    /**
     * The day rows are the record, not the date range.
     *
     * They already account for the weekends and public holidays that
     * subtracting one date from another counts as leave. A week off from
     * Monday to Friday is five days; the same request read as a range across a
     * bank holiday would be five when the company charged four.
     */
    public function test_days_are_counted_from_what_was_actually_taken(): void
    {
        $this->staff(42);

        // Monday to Friday on the calendar, but only four days were charged.
        $this->leave(1, [], [
            ['2024-06-03 00:00:00', 'Full day'],
            ['2024-06-04 00:00:00', 'Full day'],
            ['2024-06-06 00:00:00', 'Full day'],
            ['2024-06-07 00:00:00', 'Full day'],
        ]);

        $this->runImport(['--apply' => true]);

        $this->assertEquals(4, LeaveRequest::first()->days_requested);
    }

    /** A morning or an afternoon is half a day. */
    public function test_a_half_day_counts_as_half(): void
    {
        $this->staff(42);

        $this->leave(1, ['start_date' => '2024-06-03', 'end_date' => '2024-06-03'],
            [['2024-06-03 00:00:00', 'am']]);

        $request = tap(null, fn () => $this->runImport(['--apply' => true]));

        $request = LeaveRequest::first();

        $this->assertEquals(0.5, $request->days_requested);
        $this->assertTrue($request->is_half_day);
        $this->assertSame('am', $request->half_day_period);
    }

    /** With no day rows there is nothing to count, so the range is used and said. */
    public function test_a_request_with_no_day_record_is_counted_from_the_range_and_reported(): void
    {
        $this->staff(42);

        $this->leave(1);          // no day rows at all

        $output = $this->runImport(['--apply' => true]);

        $this->assertEquals(5, LeaveRequest::first()->days_requested);
        $this->assertStringContainsString('counted from the dates', $output);
    }

    /**
     * Separate days are kept as separate days.
     *
     * Without the list, a Monday and the Friday after it read as a five-day
     * absence — and on an annual entitlement that is most of a working week
     * charged to somebody who took two days.
     */
    public function test_scattered_days_keep_their_dates(): void
    {
        $this->staff(42);

        $this->leave(1, ['days_type' => 'Scattered Dates', 'start_date' => '2024-06-03',
            'end_date' => '2024-06-07'], [
            ['2024-06-03 00:00:00', 'Full day'],
            ['2024-06-07 00:00:00', 'Full day'],
        ]);

        $output = $this->runImport(['--apply' => true]);

        $request = LeaveRequest::first();

        $this->assertEquals(2, $request->days_requested);
        $this->assertSame(['2024-06-03', '2024-06-07'], $request->scattered_dates);
        $this->assertStringContainsString('1 scattered', $output);
    }

    // ── Leave types ───────────────────────────────────────────────────────────

    /** Ours is stored as "Sick  Leave" with two spaces; it must not double up. */
    public function test_a_type_we_already_have_is_matched_through_its_spacing(): void
    {
        $this->staff(42);
        $this->leave(1, ['leave_type' => 2]);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(1, LeaveType::where('organization_id', $this->org->id)
            ->get()->filter(fn ($t) => str_contains(strtolower($t->name), 'sick'))->count());
        $this->assertStringContainsString('2 already here', $output);
    }

    /**
     * Codes collide, and a number tacked on says nothing.
     *
     * "Sick Leave" and "Study Leave" are both SL. The first word gives up
     * another letter instead, so study leave reads as STL on a calendar.
     */
    public function test_a_colliding_code_lengthens_rather_than_numbers(): void
    {
        $this->staff(42);
        $this->leave(1, ['leave_type' => 5]);        // Study Leave

        $this->runImport(['--apply' => true]);

        $this->assertSame('STL', LeaveType::where('name', 'Study Leave')->value('code'));
    }

    /** Whether weekends count is the difference between two weeks and three. */
    public function test_a_created_type_keeps_how_its_days_are_counted(): void
    {
        $this->staff(42);
        $this->leave(1, ['leave_type' => 8]);        // Unpaid Leave, weekends count

        $this->runImport(['--apply' => true]);

        $unpaid = LeaveType::where('name', 'Unpaid Leave')->firstOrFail();

        // The application's own vocabulary, not a word of the import's choosing.
        $this->assertSame('calendar_days', $unpaid->counting_method);
        $this->assertFalse($unpaid->is_paid, 'the only kind that is not paid');
        $this->assertSame(90, $unpaid->days_per_year);
    }

    /**
     * A request stamped 1 January 1970 has no date, it has an epoch.
     *
     * The old system wrote that where a date was never set. The days
     * underneath know when the leave was, and taking the first and last of
     * them recovers it — otherwise a hundred and sixty of these sit in a 1970
     * bucket, quietly wrong in every figure that groups by year.
     */
    public function test_a_request_with_no_dates_is_dated_from_the_days_taken(): void
    {
        $this->staff(42);

        $this->leave(1, ['start_date' => '1970-01-01 00:00:00', 'end_date' => '1970-01-01 00:00:00'], [
            ['2022-07-25 00:00:00', 'Full day'],
            ['2022-07-26 00:00:00', 'Full day'],
        ]);

        $output = $this->runImport(['--apply' => true]);

        $request = LeaveRequest::firstOrFail();

        $this->assertSame('2022-07-25', $request->from_date->toDateString());
        $this->assertSame('2022-07-26', $request->to_date->toDateString());
        $this->assertStringContainsString('dated from the days taken', $output);
    }

    /** With neither, there is no leave to record. */
    public function test_a_request_with_no_dates_anywhere_is_held_back(): void
    {
        $this->staff(42);

        $this->leave(1, ['start_date' => '1970-01-01 00:00:00', 'end_date' => null]);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(0, LeaveRequest::count());
        $this->assertStringContainsString('no usable dates', $output);
    }

    // ── Decisions ─────────────────────────────────────────────────────────────

    /** Recorded as they were made, with the date somebody actually decided. */
    public function test_an_approval_keeps_its_approver_and_its_date(): void
    {
        $this->staff(42);
        $approver = $this->staff(7, 'manager@kns.co.ke');

        $this->leave(1, ['approved_by_email' => 'manager@kns.co.ke',
            'approved_at' => '2024-05-22 11:30:00']);

        $this->runImport(['--apply' => true]);

        $request = LeaveRequest::first();

        $this->assertSame('approved', $request->status);
        $this->assertSame($approver->id, $request->approved_by);
        $this->assertSame('2024-05-22', $request->actioned_at->toDateString());
    }

    /**
     * An approver at another company leaves the name off, not the record.
     *
     * That somebody signed it off, and when, is the fact worth keeping.
     */
    public function test_an_approver_we_do_not_have_is_named_and_the_leave_kept(): void
    {
        $this->staff(42);

        $this->leave(1, ['approved_by_email' => 'someone@tiberbu.com',
            'approved_at' => '2024-05-22 11:30:00']);

        $output = $this->runImport(['--apply' => true]);

        $request = LeaveRequest::first();

        $this->assertSame('approved', $request->status);
        $this->assertNull($request->approved_by);
        $this->assertNotNull($request->actioned_at);
        $this->assertStringContainsString('someone@tiberbu.com', $output);
    }

    public function test_declined_becomes_rejected_and_keeps_the_reason(): void
    {
        $this->staff(42);

        $this->leave(1, ['status' => 'Declined',
            'decline_reason' => '<p>Cover not arranged</p>']);

        $this->runImport(['--apply' => true]);

        $this->assertSame('rejected', LeaveRequest::first()->status);
        $this->assertSame('Cover not arranged', LeaveRequest::first()->rejection_reason);
    }

    // ── Reasons ───────────────────────────────────────────────────────────────

    /**
     * A reason is a sentence, so the editor's markup goes.
     *
     * Nobody writes a bulleted list explaining why they need Thursday off, and
     * leaving the HTML would mean another place rendering raw markup on screen.
     */
    public function test_a_reason_comes_across_as_a_sentence(): void
    {
        $this->staff(42);

        $this->leave(1, ['leave_reason' =>
            '<p style="font-family: Calibri">Attending my sister&#39;s wedding</p>'
            . '<p>in Kisumu.</p>']);

        $this->runImport(['--apply' => true]);

        $this->assertSame("Attending my sister's wedding in Kisumu.", LeaveRequest::first()->reason);
    }

    /** The column now holds what the form has always let somebody type. */
    public function test_a_long_reason_is_not_truncated(): void
    {
        $this->staff(42);

        $long = str_repeat('This needs a careful explanation. ', 15);   // ~495 chars
        $this->leave(1, ['leave_reason' => "<p>{$long}</p>"]);

        $this->runImport(['--apply' => true]);

        $this->assertGreaterThan(400, mb_strlen((string) LeaveRequest::first()->reason));
    }

    // ── What it will not do ───────────────────────────────────────────────────

    /** Annual and unpaid are the same number of days and a different thing. */
    public function test_a_request_naming_no_leave_type_is_held_back(): void
    {
        $this->staff(42);

        $this->leave(1, ['leave_type' => null]);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(0, LeaveRequest::count());
        $this->assertStringContainsString('names no leave type', $output);
    }

    public function test_leave_belonging_to_somebody_not_imported_is_skipped_and_counted(): void
    {
        $this->staff(42);

        $this->leave(1);
        $this->leave(2, ['employee' => 999]);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(1, LeaveRequest::count());
        $this->assertStringContainsString('1 nobody to attach to', $output);
    }

    // ── The usual promises ────────────────────────────────────────────────────

    public function test_nothing_is_written_without_apply(): void
    {
        $this->staff(42);
        $this->leave(1);

        $output = $this->runImport();

        $this->assertSame(0, LeaveRequest::count());
        $this->assertStringContainsString('Nothing will be written', $output);
    }

    public function test_running_it_twice_updates_rather_than_duplicates(): void
    {
        $this->staff(42);
        $this->leave(1);

        $this->runImport(['--apply' => true]);

        DB::connection('plm')->table('mdl_hr_leave')->where('id', 1)
            ->update(['status' => 'Declined']);

        $output = $this->runImport(['--apply' => true]);

        $this->assertSame(1, LeaveRequest::count());
        $this->assertSame('rejected', LeaveRequest::first()->status);
        $this->assertStringContainsString('0 created, 1 updated', $output);
    }
}
