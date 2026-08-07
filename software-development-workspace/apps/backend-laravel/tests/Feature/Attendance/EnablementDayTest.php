<?php

namespace Tests\Feature\Attendance;

use App\Models\Account\Account;
use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendancePolicy;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Task\Task;
use App\Models\User;
use App\Support\Authorization\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The one day a week for training, certification, research and improvement.
 *
 * Asked for rather than taken, and decided by the person accountable for the
 * work it displaces. The conflicts are surfaced to both sides — a rule that
 * silently refuses teaches people to stop asking, and one that silently allows
 * leaves a manager approving a day they would have refused had they known the
 * client is on site.
 */
class EnablementDayTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        AttendancePolicy::forOrganization($this->org->id);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function employee(string $first = 'Test', ?int $reportsTo = null, bool $withLogin = true): Employee
    {
        $employee = Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => $first, 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'reports_to' => $reportsTo,
        ]);

        if ($withLogin) {
            $employee->update(['user_id' => User::factory()->create()->id]);
        }

        return $employee->fresh();
    }

    private function signIn(Employee $employee, array $permissions = []): Employee
    {
        $user = $employee->user ?? User::factory()->create();

        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization',
            'permissions' => $permissions ?: [
                Permission::ATTENDANCE_VIEW_OWN, Permission::ATTENDANCE_RECORD,
            ],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        $employee->update(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/attendance/enablement{$path}?org={$this->org->slug}";
    }

    /** A Wednesday comfortably in the future, so plan-ahead limits are clear. */
    private function nextWednesday(): CarbonImmutable
    {
        return CarbonImmutable::parse('next wednesday')->startOfDay();
    }

    // ── Asking ────────────────────────────────────────────────────────────

    public function test_somebody_can_ask_for_an_enablement_day(): void
    {
        $manager = $this->employee('Manager');
        $this->signIn($this->employee('Learner', reportsTo: $manager->id));

        $this->postJson($this->url('/request'), [
            'work_date' => $this->nextWednesday()->toDateString(),
            'note' => 'Sitting the Fortinet NSE 5 exam.',
        ])->assertOk()->assertJsonPath('data.status', 'requested');

        // Worked from home by definition, so the plan says so rather than
        // making somebody state it twice.
        $this->assertSame('home', AttendanceDay::first()->planned_location);
    }

    /** A request nobody is shown is a request nobody answers. */
    public function test_asking_tells_the_line_manager(): void
    {
        $manager = $this->employee('Manager');
        $this->signIn($this->employee('Learner', reportsTo: $manager->id));

        $this->postJson($this->url('/request'), [
            'work_date' => $this->nextWednesday()->toDateString(),
        ])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $manager->user_id, 'type' => 'attendance.enablement_requested',
        ]);
    }

    /**
     * One a week, counting requested alongside approved. A pending request is a
     * claim on the week, and letting somebody ask for three while waiting would
     * make the rule meaningless.
     */
    public function test_only_one_enablement_day_a_week(): void
    {
        $this->signIn($this->employee('Learner'));
        $wednesday = $this->nextWednesday();

        $this->postJson($this->url('/request'), ['work_date' => $wednesday->toDateString()])->assertOk();

        $this->postJson($this->url('/request'), ['work_date' => $wednesday->addDay()->toDateString()])
            ->assertJsonValidationErrorFor('work_date');
    }

    public function test_the_following_week_is_a_fresh_allowance(): void
    {
        $this->signIn($this->employee('Learner'));
        $wednesday = $this->nextWednesday();

        $this->postJson($this->url('/request'), ['work_date' => $wednesday->toDateString()])->assertOk();

        $this->postJson($this->url('/request'), ['work_date' => $wednesday->addWeek()->toDateString()])
            ->assertOk();
    }

    /** The point of the day is to plan for it. */
    public function test_an_enablement_day_cannot_be_claimed_after_the_fact(): void
    {
        $this->signIn($this->employee('Learner'));

        $this->postJson($this->url('/request'), ['work_date' => now()->subWeek()->toDateString()])
            ->assertJsonValidationErrorFor('work_date');
    }

    public function test_a_non_working_day_cannot_be_an_enablement_day(): void
    {
        $this->signIn($this->employee('Learner'));

        $this->postJson($this->url('/request'), [
            'work_date' => CarbonImmutable::parse('next saturday')->toDateString(),
        ])->assertJsonValidationErrorFor('work_date');
    }

    // ── What is in the way ────────────────────────────────────────────────

    /**
     * Checked before committing to ask, so somebody can see the client is on
     * site on Thursday and quietly ask for Friday instead — rather than putting
     * a request in front of their manager only to withdraw it.
     */
    public function test_a_clear_day_reports_no_conflicts(): void
    {
        $this->signIn($this->employee('Learner'));

        $this->postJson($this->url('/check'), ['work_date' => $this->nextWednesday()->toDateString()])
            ->assertOk()
            ->assertJsonCount(0, 'conflicts');
    }

    /**
     * The hardest conflict there is: somebody else has arranged their day
     * around it.
     */
    public function test_a_customer_site_day_blocks_the_request(): void
    {
        $employee = $this->signIn($this->employee('Learner'));
        $date = $this->nextWednesday();

        AttendanceDay::create([
            'organization_id' => $this->org->id, 'employee_id' => $employee->id,
            'work_date' => $date->toDateString(), 'planned_location' => 'customer_site',
            'account_id' => Account::create([
                'organization_id' => $this->org->id, 'name' => 'Safaricom',
            ])->id,
        ]);

        $this->postJson($this->url('/check'), ['work_date' => $date->toDateString()])
            ->assertOk()
            ->assertJsonPath('conflicts.0.kind', 'customer_engagement')
            ->assertJsonPath('conflicts.0.severity', 'blocking');
    }

    /**
     * A warning, not a block. Work due is the ordinary condition of having a
     * job, and treating it as disqualifying means the day exists only on paper.
     */
    public function test_work_falling_due_is_a_warning_rather_than_a_bar(): void
    {
        $employee = $this->signIn($this->employee('Learner'));
        $date = $this->nextWednesday();

        Task::create([
            'organization_id' => $this->org->id, 'assigned_to' => $employee->id,
            'title' => 'Cut over the firewall', 'category' => 'project',
            'deadline' => $date->toDateString(),
        ]);

        $this->postJson($this->url('/check'), ['work_date' => $date->toDateString()])
            ->assertOk()
            ->assertJsonPath('conflicts.0.kind', 'work_due')
            ->assertJsonPath('conflicts.0.severity', 'warning');

        // And it can still be asked for — the manager weighs it.
        $this->postJson($this->url('/request'), ['work_date' => $date->toDateString()])->assertOk();
    }

    /**
     * Internal and admin work is exactly what an enablement day makes room
     * around. Counting it would mean nobody is ever eligible.
     */
    public function test_internal_work_is_not_treated_as_a_conflict(): void
    {
        $employee = $this->signIn($this->employee('Learner'));
        $date = $this->nextWednesday();

        Task::create([
            'organization_id' => $this->org->id, 'assigned_to' => $employee->id,
            'title' => 'Tidy the shared drive', 'category' => 'internal',
            'deadline' => $date->toDateString(),
        ]);

        $this->postJson($this->url('/check'), ['work_date' => $date->toDateString()])
            ->assertOk()->assertJsonCount(0, 'conflicts');
    }

    public function test_the_check_reports_a_day_already_taken_that_week(): void
    {
        $this->signIn($this->employee('Learner'));
        $wednesday = $this->nextWednesday();

        $this->postJson($this->url('/request'), ['work_date' => $wednesday->toDateString()])->assertOk();

        $this->postJson($this->url('/check'), ['work_date' => $wednesday->addDay()->toDateString()])
            ->assertOk()
            ->assertJsonPath('already_taken', $wednesday->toDateString());
    }

    // ── Deciding ──────────────────────────────────────────────────────────

    public function test_the_manager_sees_what_is_waiting_on_them(): void
    {
        $manager = $this->employee('Manager');
        $this->signIn($this->employee('Learner', reportsTo: $manager->id));

        $this->postJson($this->url('/request'), ['work_date' => $this->nextWednesday()->toDateString()])
            ->assertOk();

        $this->signIn($manager, [Permission::ATTENDANCE_VIEW_TEAM]);

        $this->getJson($this->url('/pending'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.name', 'Learner Person');
    }

    /**
     * Shown to the approver, so the decision is made knowing what the day
     * displaces rather than on the strength of the date alone.
     */
    public function test_the_pending_list_carries_the_conflicts(): void
    {
        $manager  = $this->employee('Manager');
        $employee = $this->signIn($this->employee('Learner', reportsTo: $manager->id));
        $date = $this->nextWednesday();

        Task::create([
            'organization_id' => $this->org->id, 'assigned_to' => $employee->id,
            'title' => 'Cut over the firewall', 'category' => 'support',
            'deadline' => $date->toDateString(),
        ]);

        $this->postJson($this->url('/request'), ['work_date' => $date->toDateString()])->assertOk();

        $this->signIn($manager, [Permission::ATTENDANCE_VIEW_TEAM]);

        $this->getJson($this->url('/pending'))
            ->assertOk()
            ->assertJsonPath('data.0.conflicts.0.kind', 'work_due');
    }

    public function test_a_manager_approves_and_the_requester_is_told(): void
    {
        $manager  = $this->employee('Manager');
        $employee = $this->signIn($this->employee('Learner', reportsTo: $manager->id));

        $this->postJson($this->url('/request'), ['work_date' => $this->nextWednesday()->toDateString()])
            ->assertOk();

        $day = AttendanceDay::firstOrFail();
        $this->signIn($manager, [Permission::ATTENDANCE_VIEW_TEAM]);

        $this->postJson($this->url("/{$day->id}/decide"), ['approved' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->user_id, 'type' => 'attendance.enablement_approved',
        ]);
    }

    /**
     * A declined day is an ordinary day again. Leaving the flag set would count
     * it in the enablement figures and leave the plan saying "home" for a day
     * nobody agreed to.
     */
    public function test_declining_returns_the_day_to_an_ordinary_one(): void
    {
        $manager = $this->employee('Manager');
        $this->signIn($this->employee('Learner', reportsTo: $manager->id));

        $this->postJson($this->url('/request'), ['work_date' => $this->nextWednesday()->toDateString()])
            ->assertOk();

        $day = AttendanceDay::firstOrFail();
        $this->signIn($manager, [Permission::ATTENDANCE_VIEW_TEAM]);

        $this->postJson($this->url("/{$day->id}/decide"), [
            'approved' => false, 'note' => 'The cutover is that week.',
        ])->assertOk()->assertJsonPath('data.status', 'declined');

        $fresh = $day->fresh();
        $this->assertFalse($fresh->is_enablement_day);
        $this->assertNull($fresh->planned_location);
    }

    /** A declined week is free again, rather than spent on a refusal. */
    public function test_a_declined_day_does_not_use_up_the_week(): void
    {
        $manager = $this->employee('Manager');
        $learner = $this->signIn($this->employee('Learner', reportsTo: $manager->id));
        $wednesday = $this->nextWednesday();

        $this->postJson($this->url('/request'), ['work_date' => $wednesday->toDateString()])->assertOk();
        $day = AttendanceDay::firstOrFail();

        $this->signIn($manager, [Permission::ATTENDANCE_VIEW_TEAM]);
        $this->postJson($this->url("/{$day->id}/decide"), ['approved' => false])->assertOk();

        $this->signIn($learner);
        $this->postJson($this->url('/request'), ['work_date' => $wednesday->addDay()->toDateString()])
            ->assertOk();
    }

    /**
     * An enablement day displaces work other people are waiting on, so
     * self-approval would make the request a formality.
     */
    public function test_nobody_approves_their_own_enablement_day(): void
    {
        $employee = $this->signIn($this->employee('Learner'), [
            Permission::ATTENDANCE_VIEW_OWN, Permission::ATTENDANCE_RECORD, Permission::ATTENDANCE_MANAGE,
        ]);

        $this->postJson($this->url('/request'), ['work_date' => $this->nextWednesday()->toDateString()])
            ->assertOk();

        $this->postJson($this->url('/' . AttendanceDay::firstOrFail()->id . '/decide'), ['approved' => true])
            ->assertForbidden();
    }

    public function test_somebody_unrelated_cannot_decide(): void
    {
        $manager = $this->employee('Manager');
        $this->signIn($this->employee('Learner', reportsTo: $manager->id));

        $this->postJson($this->url('/request'), ['work_date' => $this->nextWednesday()->toDateString()])
            ->assertOk();

        $day = AttendanceDay::firstOrFail();
        $this->signIn($this->employee('Bystander'), [Permission::ATTENDANCE_VIEW_OWN]);

        $this->postJson($this->url("/{$day->id}/decide"), ['approved' => true])->assertForbidden();
    }

    public function test_a_decided_day_cannot_be_decided_again(): void
    {
        $manager = $this->employee('Manager');
        $this->signIn($this->employee('Learner', reportsTo: $manager->id));

        $this->postJson($this->url('/request'), ['work_date' => $this->nextWednesday()->toDateString()])
            ->assertOk();

        $day = AttendanceDay::firstOrFail();
        $this->signIn($manager, [Permission::ATTENDANCE_VIEW_TEAM]);

        $this->postJson($this->url("/{$day->id}/decide"), ['approved' => true])->assertOk();
        $this->postJson($this->url("/{$day->id}/decide"), ['approved' => false])->assertStatus(422);
    }

    // ── Withdrawing ───────────────────────────────────────────────────────

    public function test_an_undecided_request_can_be_withdrawn(): void
    {
        $this->signIn($this->employee('Learner'));
        $wednesday = $this->nextWednesday();

        $this->postJson($this->url('/request'), ['work_date' => $wednesday->toDateString()])->assertOk();

        $this->deleteJson($this->url('/' . AttendanceDay::firstOrFail()->id))
            ->assertOk()
            ->assertJsonPath('data.status', null);

        // And the week is free again.
        $this->postJson($this->url('/request'), ['work_date' => $wednesday->addDay()->toDateString()])
            ->assertOk();
    }

    public function test_an_approved_day_cannot_be_withdrawn_by_the_requester(): void
    {
        $manager = $this->employee('Manager');
        $learner = $this->signIn($this->employee('Learner', reportsTo: $manager->id));

        $this->postJson($this->url('/request'), ['work_date' => $this->nextWednesday()->toDateString()])
            ->assertOk();
        $day = AttendanceDay::firstOrFail();

        $this->signIn($manager, [Permission::ATTENDANCE_VIEW_TEAM]);
        $this->postJson($this->url("/{$day->id}/decide"), ['approved' => true])->assertOk();

        $this->signIn($learner);
        $this->deleteJson($this->url("/{$day->id}"))->assertStatus(422);
    }
}
